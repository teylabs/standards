<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\CruddyGuard;
use Tey\Standards\Source;

/** Conservative AST checks; unresolved dynamic receivers remain a review concern. */
final class ControllerBodies
{
    public function __construct(private readonly Configuration $config, private readonly Source $source)
    {
        if ($config->controllerResponseHelperPattern !== null) {
            set_error_handler(static function (): never {
                throw new InvalidArgumentException('controllerResponseHelperPattern must be a valid regular expression.');
            });
            try {
                preg_match($config->controllerResponseHelperPattern, '');
            } finally {
                restore_error_handler();
            }
        }
    }

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        $violations = [];
        $roots = array_values(array_unique([...$this->config->controllerRoots, ...array_keys($this->config->classRoots)]));

        foreach ($this->source->files($roots) as $path => $text) {
            foreach ((new NodeFinder)->findInstanceOf($this->source->nodes($path), Node\Stmt\Class_::class) as $class) {
                if ($class->name === null || ! str_ends_with($class->name->toString(), $this->config->controllerSuffix)) {
                    continue;
                }

                $properties = [];
                foreach ($class->getProperties() as $property) {
                    foreach ($property->props as $prop) {
                        $properties[$prop->name->toString()] = $this->type($property->type);
                    }
                }
                foreach ($class->getMethod('__construct')->params ?? [] as $param) {
                    if ($param->flags !== 0 && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                        $properties[$param->var->name] = $this->type($param->type);
                    }
                }

                foreach ($class->getMethods() as $method) {
                    $subject = $class->name->toString().'::'.$method->name->toString();
                    $checks = $this->methodChecks($method, $properties);
                    // A public method overriding a same-named method of a vendor parent (Passport's AuthorizationController::authorize(), say)
                    // keeps the parent's response contract, so `response negotiation` is skipped under exactly rule 21's name allowance:
                    // CruddyGuard::overridesVendor(), the same resolver. Every other body check still applies.
                    if (in_array('response negotiation', $checks, true) && $method->isPublic() && ! $method->isStatic()
                        && $this->vendorGuard()->overridesVendor($this->config->base.'/'.$path, $class->name->toString(), $method->name->toString())) {
                        $checks = array_values(array_diff($checks, ['response negotiation']));
                    }
                    foreach ($checks as $check) {
                        $violations[] = ['target' => $path, 'check' => $check.' | '.$subject];
                    }
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    private ?CruddyGuard $vendorGuard = null;

    /** Rule 21's resolver, used only for its vendor-parent override check (composer's static maps, cached per run). */
    private function vendorGuard(): CruddyGuard
    {
        return $this->vendorGuard ??= new CruddyGuard($this->config->base, [], []);
    }

    /** @param array<string, string|null> $properties
     * @return list<string>
     */
    private function methodChecks(Node\Stmt\ClassMethod $method, array $properties): array
    {
        $checks = [];
        $name = $method->name->toString();
        if (($method->isPrivate() || $method->isProtected()) && $name !== '__construct'
            && ! $this->responseHelper($method)
            && ($this->config->controllerResponseHelperPattern === null || preg_match($this->config->controllerResponseHelperPattern, $name) !== 1)) {
            $checks[] = 'non-response helper';
        }

        $variables = [];
        foreach ($method->params as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $variables[$param->var->name] = $this->type($param->type);
            }
        }

        $operations = [];
        $nodes = (new NodeFinder)->find($method->stmts ?? [], fn (Node $node): bool => $node instanceof Node\Expr);
        foreach ($nodes as $node) {
            if ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                $variables[$node->var->name] = $this->receiver($node->expr, $variables, $properties);
            }

            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                && strtolower($node->name->toString()) === 'validator') {
                $checks[] = 'inline validation';
            }

            if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
                $class = $node->class->toString();
                $called = $node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : '';
                if (strtolower($class) === 'illuminate\\support\\facades\\validator' && $called === 'make') {
                    $checks[] = 'inline validation';
                }
                if (strtolower($class) === 'illuminate\\support\\facades\\db') {
                    $checks[] = 'direct persistence';
                }
            }

            if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
                $called = $node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : '';
                $receiver = $this->receiver($node->var, $variables, $properties);
                $request = $this->request($receiver) || ($node->var instanceof Node\Expr\Variable && $node->var->name === 'request');
                $thisCall = $node->var instanceof Node\Expr\Variable && $node->var->name === 'this';
                if ($called === 'validate' && ($request || $thisCall)) {
                    $checks[] = 'inline validation';
                }
                if ($request && in_array($called, ['wantsjson', 'expectsjson', 'ajax', 'acceptsjson'], true)) {
                    $checks[] = 'response negotiation';
                }
            }

            if ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
                $receiver = $node instanceof Node\Expr\StaticCall
                    ? ($node->class instanceof Node\Name ? $node->class->toString() : null)
                    : $this->receiver($node->var, $variables, $properties);
                $called = $node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : '';
                if ($this->model($receiver) && in_array($called, ['create', 'update', 'save', 'delete', 'forcedelete', 'insert', 'upsert', 'updateorcreate', 'firstorcreate', 'destroy', 'increment', 'decrement', 'savequietly', 'updatequietly', 'deletequietly'], true)) {
                    $checks[] = 'direct persistence';
                }
                if ($this->operation($receiver)) {
                    $operations[strtolower((string) $receiver)] = true;
                }
            }

            if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name && $this->operation($node->class->toString())) {
                $operations[strtolower($node->class->toString())] = true;
            }
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Expr && $this->operation($this->receiver($node->name, $variables, $properties))) {
                $operations[strtolower((string) $this->receiver($node->name, $variables, $properties))] = true;
            }
        }

        if (in_array($name, [...CruddyGuard::ACTIONS, '__invoke'], true) && count($operations) > 1) {
            $checks[] = 'multiple operations';
        }

        return array_values(array_unique($checks));
    }

    private function type(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            return $this->type($type->type);
        }

        return $type instanceof Node\Name ? $type->toString() : null;
    }

    /** @param array<string, string|null> $variables
     * @param  array<string, string|null>  $properties
     */
    private function receiver(Node\Expr $expression, array $variables, array $properties): ?string
    {
        if ($expression instanceof Node\Expr\Variable && is_string($expression->name)) {
            return $variables[$expression->name] ?? null;
        }
        if ($expression instanceof Node\Expr\PropertyFetch && $expression->var instanceof Node\Expr\Variable
            && $expression->var->name === 'this' && $expression->name instanceof Node\Identifier) {
            return $properties[$expression->name->toString()] ?? null;
        }
        if (($expression instanceof Node\Expr\StaticCall || $expression instanceof Node\Expr\New_) && $expression->class instanceof Node\Name) {
            return $expression->class->toString();
        }
        if ($expression instanceof Node\Expr\MethodCall || $expression instanceof Node\Expr\NullsafeMethodCall) {
            $receiver = $this->receiver($expression->var, $variables, $properties);
            $method = $expression->name instanceof Node\Identifier ? strtolower($expression->name->toString()) : '';
            if ($this->model($receiver) && (str_starts_with($method, 'where') || in_array($method, ['query', 'newquery', 'with', 'without', 'orderby', 'orderbydesc', 'latest', 'oldest', 'limit', 'take', 'select', 'addselect', 'lockforupdate', 'forcefill', 'fill', 'find', 'findorfail', 'first', 'firstorfail'], true))) {
                return $receiver;
            }

            return null;
        }
        if ($expression instanceof Node\Expr\FuncCall && $expression->name instanceof Node\Name) {
            $name = strtolower($expression->name->toString());
            if ($name === 'request') {
                return 'Illuminate\\Http\\Request';
            }
            $argument = $expression->getArgs()[0]->value ?? null;
            if (in_array($name, ['app', 'resolve'], true) && $argument instanceof Node\Expr\ClassConstFetch
                && $argument->class instanceof Node\Name && $argument->name instanceof Node\Identifier && strtolower($argument->name->toString()) === 'class') {
                return $argument->class->toString();
            }
        }

        return null;
    }

    private function request(?string $class): bool
    {
        return $class !== null && ($class === 'Illuminate\\Http\\Request' || str_ends_with($class, 'Request')
            || is_a($class, 'Illuminate\\Http\\Request', true));
    }

    private function model(?string $class): bool
    {
        return $class !== null && (str_contains($class, '\\Models\\')
            || $class === 'Illuminate\\Database\\Eloquent\\Builder'
            || is_a($class, 'Illuminate\\Database\\Eloquent\\Model', true)
            || is_a($class, 'Illuminate\\Database\\Eloquent\\Builder', true));
    }

    private function operation(?string $class): bool
    {
        if ($class === null) {
            return false;
        }
        foreach ($this->config->controllerNonOperationNamespaces as $namespace) {
            if (str_contains('\\'.$class.'\\', '\\'.trim($namespace, '\\').'\\')) {
                return false;
            }
        }
        foreach ($this->config->controllerOperationNamespaces as $namespace) {
            if (str_contains('\\'.$class.'\\', '\\'.trim($namespace, '\\').'\\')) {
                return true;
            }
        }
        $short = substr(strrchr('\\'.$class, '\\') ?: $class, 1);
        foreach ($this->config->controllerOperationSuffixes as $suffix) {
            if ($suffix !== '' && $short !== $suffix && str_ends_with($short, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function responseHelper(Node\Stmt\ClassMethod $method): bool
    {
        $statements = $method->stmts ?? [];
        if (count($statements) !== 1 || ! $statements[0] instanceof Node\Stmt\Return_ || $statements[0]->expr === null
            || ! $this->response($statements[0]->expr)) {
            return false;
        }

        foreach ((new NodeFinder)->find([$statements[0]->expr], fn (Node $node): bool => $node instanceof Node\Expr\CallLike || $node instanceof Node\Expr\Assign) as $call) {
            if (! $call instanceof Node\Expr || ! $this->response($call)) {
                return false;
            }
        }

        return true;
    }

    private function response(Node\Expr $expression): bool
    {
        if ($expression instanceof Node\Expr\FuncCall && $expression->name instanceof Node\Name) {
            return in_array(strtolower($expression->name->toString()), ['response', 'redirect', 'to_route', 'back'], true);
        }
        if ($expression instanceof Node\Expr\StaticCall && $expression->class instanceof Node\Name && $expression->name instanceof Node\Identifier) {
            return in_array(strtolower($expression->class->toString()), ['inertia\\inertia', 'illuminate\\support\\facades\\response', 'illuminate\\support\\facades\\redirect'], true)
                && in_array(strtolower($expression->name->toString()), ['render', 'modal', 'json', 'make', 'route', 'back', 'to', 'away', 'nocontent'], true);
        }
        if ($expression instanceof Node\Expr\MethodCall && $expression->name instanceof Node\Identifier) {
            return $this->response($expression->var) && (str_starts_with(strtolower($expression->name->toString()), 'toast') || in_array(strtolower($expression->name->toString()), ['json', 'route', 'back', 'to', 'with', 'withinput', 'witherrors', 'setstatuscode', 'header', 'nocontent', 'toresponse'], true));
        }

        return false;
    }
}
