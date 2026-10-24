<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

final class Toast
{
    public function __construct(private readonly Configuration $config, private readonly Source $source) {}

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        $violations = [];
        $finder = new NodeFinder;
        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            if ($this->source->inside($path, $this->config->uiRoots)) {
                continue;
            }
            foreach ($finder->find($this->source->nodes($path), fn (Node $node): bool => $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) as $call) {
                if ((! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\StaticCall) || $call->isFirstClassCallable() || ! $call->name instanceof Node\Identifier) {
                    continue;
                }
                $method = strtolower($call->name->toString());
                $key = $call->getArgs()[0]->value ?? null;
                $outcome = $key instanceof Node\Scalar\String_ && in_array($key->value, ['status', 'success', 'message', 'error', 'flash'], true);
                $session = $call instanceof Node\Expr\MethodCall && $call->var instanceof Node\Expr\FuncCall && $call->var->name instanceof Node\Name && $call->var->name->toString() === 'session';
                $facade = $call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name ? $call->class->toString() : null;
                if (($method === 'with' && $outcome) || ($method === 'flash' && ($session || $facade === 'Illuminate\Support\Facades\Session' || ($facade === 'Inertia\Inertia' && $outcome)))) {
                    $violations[] = ['target' => $path, 'check' => 'flash outcomes through Toastable, not session keys'];
                }
            }
        }
        $invokables = $this->mutatingInvokables();
        foreach ($this->source->files($this->config->controllerRoots) as $path => $text) {
            if ($this->source->inside($path, $this->config->uiRoots)) {
                continue;
            }
            foreach ($finder->findInstanceOf($this->source->nodes($path), Node\Stmt\Class_::class) as $class) {
                foreach ($class->getMethods() as $method) {
                    $name = $method->name->toString();
                    if (! $method->isPublic() || (! in_array($name, ['store', 'update', 'destroy'], true) && ! ($name === '__invoke' && isset($class->namespacedName) && in_array($class->namespacedName->toString(), $invokables, true)))) {
                        continue;
                    }
                    foreach ($this->returns($method->stmts ?? []) as $return) {
                        if ($return->expr !== null && $this->redirectWithoutToast($return->expr)) {
                            $violations[] = ['target' => $path, 'check' => $class->name.'::'.$name.' redirect must chain ->toast*()'];
                        }
                    }
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    /** @param array<Node> $nodes
     * @return list<Node\Stmt\Return_>
     */
    private function returns(array $nodes): array
    {
        $returns = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_) {
                continue;
            }
            if ($node instanceof Node\Stmt\Return_) {
                $returns[] = $node;

                continue;
            }
            foreach ($node->getSubNodeNames() as $key) {
                $child = $node->$key;
                if ($child instanceof Node) {
                    $returns = [...$returns, ...$this->returns([$child])];
                } elseif (is_array($child)) {
                    $returns = [...$returns, ...$this->returns(array_values(array_filter($child, fn ($value): bool => $value instanceof Node)))];
                }
            }
        }

        return $returns;
    }

    private function redirectWithoutToast(Node\Expr $expression): bool
    {
        if ($expression instanceof Node\Expr\Ternary) {
            return ($expression->if !== null && $this->redirectWithoutToast($expression->if)) || $this->redirectWithoutToast($expression->else);
        }
        if ($expression instanceof Node\Expr\Match_) {
            foreach ($expression->arms as $arm) {
                if ($this->redirectWithoutToast($arm->body)) {
                    return true;
                }
            }

            return false;
        }
        $toast = false;
        while ($expression instanceof Node\Expr\MethodCall || $expression instanceof Node\Expr\NullsafeMethodCall) {
            $toast = $toast || ($expression->name instanceof Node\Identifier && preg_match('/^toast(?:[A-Z].*)?$/', $expression->name->toString()) === 1);
            $expression = $expression->var;
        }
        $redirect = ($expression instanceof Node\Expr\FuncCall && $expression->name instanceof Node\Name && in_array($expression->name->toString(), ['redirect', 'back', 'to_route'], true))
            || ($expression instanceof Node\Expr\StaticCall && $expression->class instanceof Node\Name && $expression->class->toString() === 'Illuminate\Support\Facades\Redirect');

        return $redirect && ! $toast;
    }

    private function groupController(Node $node): ?string
    {
        for ($parent = $node->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            if (! $parent instanceof Node\Expr\MethodCall || ! $parent->name instanceof Node\Identifier || strtolower($parent->name->toString()) !== 'group') {
                continue;
            }
            $builder = $parent->var;
            while ($builder instanceof Node\Expr\MethodCall || $builder instanceof Node\Expr\StaticCall) {
                if ($builder->name instanceof Node\Identifier && strtolower($builder->name->toString()) === 'controller' && ! $builder->isFirstClassCallable()) {
                    $controller = $builder->getArgs()[0]->value ?? null;
                    if ($controller instanceof Node\Expr\ClassConstFetch && $controller->class instanceof Node\Name) {
                        return $controller->class->toString();
                    }
                }
                if (! $builder instanceof Node\Expr\MethodCall) {
                    break;
                }
                $builder = $builder->var;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function mutatingInvokables(): array
    {
        $classes = [];
        foreach ($this->source->files($this->config->routeRoots) as $path => $text) {
            foreach ((new NodeFinder)->find($this->source->nodes($path), fn (Node $node): bool => $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall) as $call) {
                if ((! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\StaticCall) || $call->isFirstClassCallable() || ! $call->name instanceof Node\Identifier || ! in_array(strtolower($call->name->toString()), ['post', 'put', 'patch', 'delete', 'options', 'match', 'any'], true)) {
                    continue;
                }
                $root = $call;
                while ($root instanceof Node\Expr\MethodCall) {
                    $root = $root->var;
                }
                if (! $root instanceof Node\Expr\StaticCall || ! $root->class instanceof Node\Name || $root->class->toString() !== 'Illuminate\Support\Facades\Route') {
                    continue;
                }
                $arguments = $call->getArgs();
                if (strtolower($call->name->toString()) === 'match') {
                    $verbs = $arguments[0]->value ?? null;
                    if ($verbs instanceof Node\Expr\Array_ && array_filter($verbs->items, fn ($item): bool => $item->value instanceof Node\Scalar\String_ && ! in_array(strtoupper($item->value->value), ['GET', 'HEAD'], true)) === []) {
                        continue;
                    }
                }
                $action = $arguments[strtolower($call->name->toString()) === 'match' ? 2 : 1]->value ?? null;
                if ($action instanceof Node\Expr\Array_) {
                    $method = $action->items[1]->value ?? null;
                    $action = $method instanceof Node\Scalar\String_ && $method->value === '__invoke' ? ($action->items[0]->value ?? null) : null;
                }
                if ($action instanceof Node\Expr\ClassConstFetch && $action->class instanceof Node\Name && $action->name instanceof Node\Identifier && strtolower($action->name->toString()) === 'class') {
                    $classes[] = $action->class->toString();
                } elseif ($action instanceof Node\Scalar\String_) {
                    if ($action->value === '__invoke') {
                        $controller = $this->groupController($call);
                        if ($controller !== null) {
                            $classes[] = $controller;
                        }
                    } elseif (! str_contains($action->value, '@') || str_ends_with($action->value, '@__invoke')) {
                        $classes[] = ltrim(str_replace('@__invoke', '', $action->value), '\\');
                    }
                }
            }
        }

        return array_values(array_unique($classes));
    }
}
