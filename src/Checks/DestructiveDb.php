<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `destructive-db` (opt-in through Configuration::optionalRules): a service provider's boot() calls
 * `DB::prohibitDestructiveCommands(...)` with a guard that is true outside the testing environment, so a
 * `migrate:fresh --env=testing` that silently falls back to `.env` (no `.env.testing`) cannot wipe a local database.
 *
 * Providers are the classes extending Illuminate\Support\ServiceProvider (or named *ServiceProvider) under app/Providers
 * and the source roots. A guard passes when it is a negated testing check (`! app()->runningUnitTests()`,
 * `! $this->app->environment('testing')`), optionally joined with `&&` to negated config overrides
 * (`! config('database.allow_destructive')`). A bare call or `true` prohibits in tests too, where RefreshDatabase runs
 * migrate:fresh; a production-only guard leaves local databases open; env() in the guard is app code reading env().
 */
final class DestructiveDb
{
    private const FACADES = ['DB', 'Illuminate\Support\Facades\DB'];

    private const PROVIDER = 'Illuminate\Support\ServiceProvider';

    private const SNIPPET = "DB::prohibitDestructiveCommands(! \$this->app->runningUnitTests() && ! config('database.allow_destructive'));";

    public function __construct(private readonly Configuration $config, private readonly Source $source) {}

    /** @return list<array{target: string, check: string, line?: int, hint?: string}> */
    public function violations(): array
    {
        $finder = new NodeFinder;
        $roots = array_values(array_unique(['app/Providers', ...$this->config->sourceRoots]));
        $findings = [];
        $calls = 0;

        foreach ($this->source->files($roots) as $path => $text) {
            foreach ($finder->findInstanceOf($this->source->nodes($path), Node\Stmt\Class_::class) as $class) {
                $boot = $class->getMethod('boot');
                if ($boot === null || ! $this->provider($class)) {
                    continue;
                }
                foreach ($finder->findInstanceOf($boot->stmts ?? [], Node\Expr\StaticCall::class) as $call) {
                    if (! $call->class instanceof Node\Name || ! in_array($call->class->toString(), self::FACADES, true)
                        || ! $call->name instanceof Node\Identifier || strtolower($call->name->toString()) !== 'prohibitdestructivecommands') {
                        continue;
                    }
                    $calls++;
                    $problem = $this->problem($call);
                    if ($problem !== null) {
                        $findings[] = ['target' => $path, 'check' => $problem, 'line' => $call->getStartLine(), 'hint' => 'Use '.self::SNIPPET];
                    }
                }
            }
        }

        if ($calls === 0) {
            return [['target' => 'app/Providers/AppServiceProvider.php', 'check' => 'no DB::prohibitDestructiveCommands() guard in a service provider boot()',
                'hint' => 'Add to AppServiceProvider::boot(): '.self::SNIPPET]];
        }

        return $findings;
    }

    private function provider(Node\Stmt\Class_ $class): bool
    {
        $name = $class->name?->toString() ?? '';

        return str_ends_with($name, 'ServiceProvider') || ($class->extends !== null && $class->extends->toString() === self::PROVIDER);
    }

    /** Why the call's guard is not true exactly outside testing, or null when it is. */
    private function problem(Node\Expr\StaticCall $call): ?string
    {
        $argument = $call->getArgs()[0]->value ?? null;

        if ($argument === null || ($argument instanceof Node\Expr\ConstFetch && strtolower($argument->name->toString()) === 'true')) {
            return 'prohibitDestructiveCommands() without a guard also prohibits in tests, where RefreshDatabase runs migrate:fresh';
        }
        if ((new NodeFinder)->findFirst([$argument], fn (Node $node): bool => $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && strtolower($node->name->toString()) === 'env') !== null) {
            return 'prohibitDestructiveCommands() guard reads env() directly; read a config key backed by env in config/*.php';
        }

        $conjuncts = $this->conjuncts($argument);
        $testing = array_filter($conjuncts, $this->notTesting(...));
        $overrides = array_filter($conjuncts, $this->notConfig(...));

        if ($testing !== [] && count($testing) + count($overrides) === count($conjuncts)) {
            return null;
        }
        if ((new NodeFinder)->findFirst([$argument], $this->production(...)) !== null) {
            return 'prohibitDestructiveCommands() guard only covers production; prohibit everywhere outside testing';
        }

        return 'prohibitDestructiveCommands() guard is not recognised as true outside testing';
    }

    /** @return list<Node\Expr> the operands of a chain of `&&` */
    private function conjuncts(Node\Expr $expr): array
    {
        return $expr instanceof Node\Expr\BinaryOp\BooleanAnd || $expr instanceof Node\Expr\BinaryOp\LogicalAnd
            ? [...$this->conjuncts($expr->left), ...$this->conjuncts($expr->right)]
            : [$expr];
    }

    /** `! …->runningUnitTests()`, `! …->environment('testing')` or `…->environment() !== 'testing'`. */
    private function notTesting(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\BooleanNot) {
            $inner = $expr->expr;

            return $this->called($inner, 'runningunittests') || ($this->called($inner, 'environment') && $this->namesTesting($inner));
        }
        if ($expr instanceof Node\Expr\BinaryOp\NotIdentical || $expr instanceof Node\Expr\BinaryOp\NotEqual) {
            foreach ([[$expr->left, $expr->right], [$expr->right, $expr->left]] as [$call, $value]) {
                if ($this->called($call, 'environment') && $call instanceof Node\Expr\CallLike && $call->getArgs() === [] && $value instanceof Node\Scalar\String_ && $value->value === 'testing') {
                    return true;
                }
            }
        }

        return false;
    }

    /** `! config('…')`, `! Config::get('…')`, optionally cast to bool: an override that is off unless set. */
    private function notConfig(Node\Expr $expr): bool
    {
        if (! $expr instanceof Node\Expr\BooleanNot) {
            return false;
        }
        $inner = $expr->expr instanceof Node\Expr\Cast\Bool_ ? $expr->expr->expr : $expr->expr;

        return ($inner instanceof Node\Expr\FuncCall && $inner->name instanceof Node\Name && strtolower($inner->name->toString()) === 'config')
            || ($inner instanceof Node\Expr\StaticCall && $inner->class instanceof Node\Name && in_array($inner->class->toString(), ['Config', 'Illuminate\Support\Facades\Config'], true)
                && $inner->name instanceof Node\Identifier && in_array(strtolower($inner->name->toString()), ['get', 'boolean'], true));
    }

    private function production(Node $node): bool
    {
        return $this->called($node, 'isproduction') || ($this->called($node, 'environment') && $node instanceof Node\Expr\CallLike
            && array_filter($node->getArgs(), fn (Node\Arg $arg): bool => $arg->value instanceof Node\Scalar\String_ && $arg->value->value === 'production') !== []);
    }

    private function called(Node $node, string $method): bool
    {
        return ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall)
            && $node->name instanceof Node\Identifier && strtolower($node->name->toString()) === $method;
    }

    private function namesTesting(Node $call): bool
    {
        return $call instanceof Node\Expr\CallLike
            && array_filter($call->getArgs(), fn (Node\Arg $arg): bool => $arg->value instanceof Node\Scalar\String_ && $arg->value->value === 'testing') !== [];
    }
}
