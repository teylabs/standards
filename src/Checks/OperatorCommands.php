<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `operator` (pattern background.command): an artisan command that writes must offer a dry run and refuse production by default. A command class
 * (extending Illuminate\Console\Command) that calls a write (an Eloquent or query-builder save, update, delete, insert, create and the like, a DB or
 * Storage write, or a file write) passes when its work runs through Tey\Standards\Operator\Operator::run, or when it hand-rolls both halves: an
 * `--apply` or `--dry-run` option in its signature, and a production check (isProduction(), environment('production'), or Laravel's
 * ConfirmableTrait `confirmToProceed()`). A command the scheduler runs (`Schedule::command(...)` in routes/console.php, `$schedule->command(...)` in a
 * Console Kernel or bootstrap's withSchedule) is a scheduled job, not an operator tool: an --apply gate and a production refusal would stop it in
 * production, so it is not reported. Static: a write reached
 * through another class is not seen, and a call named like a write on something else (a cache `put`) is reported. So it is advisory unless an app
 * lists `operator` in Configuration::optionalRules.
 */
final class OperatorCommands
{
    private const COMMAND = 'Illuminate\Console\Command';

    private const OPERATOR = 'Tey\Standards\Operator\Operator';

    /** Method names that write, on a model, a query builder, a relation or a filesystem. */
    private const WRITES = ['save', 'saveQuietly', 'update', 'updateQuietly', 'delete', 'deleteQuietly', 'forceDelete', 'insert', 'insertOrIgnore', 'insertGetId', 'upsert', 'increment', 'decrement', 'create', 'createMany', 'forceCreate', 'updateOrCreate', 'firstOrCreate', 'updateOrInsert', 'sync', 'syncWithoutDetaching', 'attach', 'detach', 'truncate', 'restore', 'destroy', 'put', 'putFile', 'putFileAs', 'move', 'copy', 'statement', 'unprepared', 'touch'];

    private const FUNCTIONS = ['file_put_contents', 'unlink', 'rename', 'copy', 'rmdir'];

    public function __construct(private readonly Configuration $config, private readonly Source $source) {}

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        $violations = [];
        $finder = new NodeFinder;
        [$scheduledClasses, $scheduledNames] = $this->scheduled();

        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            foreach ($finder->findInstanceOf($this->source->nodes($path), Node\Stmt\Class_::class) as $class) {
                if ($class->extends === null || ltrim($class->extends->toString(), '\\') !== self::COMMAND) {
                    continue;
                }
                $writes = $finder->find($class->stmts, fn (Node $node): bool => (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall) && $node->name instanceof Node\Identifier && in_array($node->name->toString(), self::WRITES, true))
                    || ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && in_array(strtolower(ltrim($node->name->toString(), '\\')), self::FUNCTIONS, true)));
                if ($writes === []) {
                    continue;
                }
                $operator = fn (string $method): bool => $finder->findFirst($class->stmts, fn (Node $node): bool => $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && ltrim($node->class->toString(), '\\') === self::OPERATOR && $node->name instanceof Node\Identifier && $node->name->toString() === $method) !== null;
                if ($operator('run')) {
                    continue;
                }
                $name = $class->namespacedName?->toString() ?? (string) $class->name;
                $signature = '';
                foreach ($finder->findInstanceOf($class->stmts, Node\Stmt\PropertyProperty::class) as $property) {
                    if ($property->name->toString() === 'signature' && $property->default instanceof Node\Scalar\String_) {
                        $signature = $property->default->value;
                    }
                }
                $command = (string) preg_split('/[\s{]/', trim($signature), 2)[0];
                if (in_array($name, $scheduledClasses, true) || ($command !== '' && in_array($command, $scheduledNames, true))) {
                    continue;
                }
                if ($operator('report')) {
                    $violations[] = ['target' => $path, 'check' => $name.' writes but runs through Operator::report, which is read-only: use Operator::run'];

                    continue;
                }
                if (preg_match('/\{--(apply|dry-run)\b/', $signature) !== 1) {
                    $violations[] = ['target' => $path, 'check' => $name.' writes without a dry run: run the work through Operator::run, or add --apply (or --dry-run)'];
                }
                $guarded = $finder->findFirst($class->stmts, fn (Node $node): bool => ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) && $node->name instanceof Node\Identifier
                    && (in_array($node->name->toString(), ['isProduction', 'confirmToProceed'], true) || ($node->name->toString() === 'environment' && self::mentionsProduction($node)))) !== null;
                if (! $guarded) {
                    $violations[] = ['target' => $path, 'check' => $name.' writes without a production guard: run the work through Operator::run, or refuse production unless forced'];
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    /**
     * Commands the scheduler runs, by class (`->command(Sweep::class)`) and by name (`->command('app:sweep --force')`): `Schedule::command` and
     * `$schedule->command` calls in the source roots, routes and bootstrap.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function scheduled(): array
    {
        $finder = new NodeFinder;
        $classes = [];
        $names = [];

        foreach ($this->source->files(array_values(array_unique([...$this->config->sourceRoots, ...$this->config->routeRoots, 'routes', 'bootstrap']))) as $path => $text) {
            if (! str_contains($text, 'command(')) {
                continue;
            }

            foreach ($finder->find($this->source->nodes($path), fn (Node $node): bool => (($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && in_array(ltrim($node->class->toString(), '\\'), ['Schedule', 'Illuminate\Support\Facades\Schedule'], true))
                || $node instanceof Node\Expr\MethodCall) && $node->name instanceof Node\Identifier && $node->name->toString() === 'command') as $call) {
                $argument = $call->getArgs()[0]->value ?? null;

                if ($argument instanceof Node\Expr\ClassConstFetch && $argument->class instanceof Node\Name) {
                    $classes[] = ltrim($argument->class->toString(), '\\');
                } elseif ($argument instanceof Node\Scalar\String_) {
                    $names[] = (string) preg_split('/\s/', trim($argument->value), 2)[0];
                }
            }
        }

        return [$classes, $names];
    }

    private static function mentionsProduction(Node\Expr\CallLike $call): bool
    {
        foreach ($call->getArgs() as $argument) {
            if ($argument->value instanceof Node\Scalar\String_ && $argument->value->value === 'production') {
                return true;
            }
        }

        return false;
    }
}
