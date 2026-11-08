<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `ui.page-view-model`: every rendered page's ViewModel extends the kit's PageViewModel (directly or through an app base), because the shell's
 * breadcrumb bar and module tabs read the page's `layout()`, which only PageViewModel provides. Widgets and child ViewModels stay on the plain
 * ViewModel, and a deliberate exception (a public document drawn without the shell) sets `protected bool $standalone = true`, tey/kit's opt-out.
 *
 * A class counts as rendered as a page when the app's PHP hands it to `Inertia::render()` or `inertia()` as the props (`new X(...)`, `X::make(...)`,
 * `(new X)->toArray()`, or a variable assigned one of those in the same function), or a controller action returns `new X(...)`. Read from the parsed
 * code, so a mention in a comment or docblock is never a render site. Flagged: such a class that descends from the kit's ViewModel, not from
 * PageViewModel, and sets no `$standalone = true` on itself or an app ancestor. A class whose ancestry leaves the app's code anywhere else is
 * not read, never guessed.
 */
final readonly class UiPageViewModel
{
    public const RULE = 'ui.page-view-model';

    public const VIEW_MODEL = 'Tey\Kit\Features\Core\ViewModels\ViewModel';

    public const PAGES = ['Tey\Kit\Features\Core\ViewModels\PageViewModel', 'Tey\Kit\Features\Core\ViewModels\WorkbenchViewModel'];

    public function __construct(private Configuration $config, private Source $source) {}

    /** @return list<array{target: string, check: string, line: int}> */
    public function violations(): array
    {
        $classes = [];
        $sites = [];
        $finder = new NodeFinder;
        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            $nodes = $this->source->nodes($path);
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
                if ($class->name !== null) {
                    $classes[$class->namespacedName?->toString() ?? (string) $class->name] = $class;
                }
            }
            foreach ($finder->find($nodes, fn (Node $node): bool => $this->isRender($node)) as $call) {
                /** @var Node\Expr\StaticCall|Node\Expr\FuncCall $call */
                if (($class = $this->rendered($this->props($call), $call)) !== null) {
                    $sites[$class] ??= [$path, $call->getStartLine()];
                }
            }
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\Return_::class) as $return) {
                if ($return->expr instanceof Node\Expr\New_ && $return->expr->class instanceof Node\Name && $this->inControllerAction($return)) {
                    $sites[ltrim($return->expr->class->toString(), '\\')] ??= [$path, $return->getStartLine()];
                }
            }
        }

        $found = [];
        foreach ($sites as $name => [$path, $line]) {
            if ($this->plainViewModel($name, $classes)) {
                $found[] = ['target' => $path, 'line' => $line, 'check' => "{$name} is rendered as a page but extends the plain ViewModel: extend PageViewModel, or set `protected bool \$standalone = true` for a page drawn without the shell"];
            }
        }
        usort($found, fn (array $a, array $b): int => [$a['target'], $a['line']] <=> [$b['target'], $b['line']]);

        return $found;
    }

    private function isRender(Node $node): bool
    {
        if ($node instanceof Node\Expr\StaticCall) {
            return $node->class instanceof Node\Name && $node->name instanceof Node\Identifier && strtolower($node->name->toString()) === 'render'
                && in_array(strtolower(ltrim($node->class->toString(), '\\')), ['inertia\inertia', 'inertia'], true);
        }

        return $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && strtolower($node->name->getLast()) === 'inertia';
    }

    /** The props argument: the second positional one, or the one named `props`. */
    private function props(Node\Expr\StaticCall|Node\Expr\FuncCall $call): ?Node\Expr
    {
        foreach ($call->getArgs() as $index => $arg) {
            if ($arg->name !== null ? $arg->name->toString() === 'props' : $index === 1) {
                return $arg->value;
            }
        }

        return null;
    }

    /** The class an expression constructs, unwrapping `->toArray()` and following a variable to its assignment in the same function. */
    private function rendered(?Node\Expr $expr, Node $at, int $depth = 0): ?string
    {
        if ($expr instanceof Node\Expr\MethodCall) {
            return $expr->name instanceof Node\Identifier && $expr->name->toString() === 'toArray' ? $this->rendered($expr->var, $at, $depth) : null;
        }
        $made = $expr instanceof Node\Expr\StaticCall && $expr->name instanceof Node\Identifier && $expr->name->toString() === 'make';
        if (($expr instanceof Node\Expr\New_ || $made) && $expr->class instanceof Node\Name) {
            $name = ltrim($expr->class->toString(), '\\');

            return in_array(strtolower($name), ['self', 'static', 'parent'], true) ? $this->enclosingClass($at) : $name;
        }
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name) && $depth < 3 && ($scope = $this->enclosingFunction($at)) !== null) {
            // Only this function's own assignments: one inside a nested closure or function is another scope.
            $assigned = (new NodeFinder)->find((array) $scope->getStmts(), fn (Node $node): bool => $node instanceof Node\Expr\Assign
                && $node->var instanceof Node\Expr\Variable && $node->var->name === $expr->name && $node->getStartLine() <= $at->getStartLine()
                && $this->enclosingFunction($node) === $scope);
            $last = end($assigned);

            return $last instanceof Node\Expr\Assign ? $this->rendered($last->expr, $at, $depth + 1) : null;
        }

        return null;
    }

    private function enclosingFunction(Node $node): ?Node\FunctionLike
    {
        for ($node = $node->getAttribute('parent'); $node instanceof Node; $node = $node->getAttribute('parent')) {
            if ($node instanceof Node\FunctionLike) {
                return $node;
            }
        }

        return null;
    }

    private function enclosingClass(Node $node): ?string
    {
        for ($node = $node->getAttribute('parent'); $node instanceof Node; $node = $node->getAttribute('parent')) {
            if ($node instanceof Node\Stmt\Class_) {
                return $node->namespacedName?->toString();
            }
        }

        return null;
    }

    /** A `return new X(...)` directly in a public method of a controller (a class named with the controller suffix). */
    private function inControllerAction(Node\Stmt\Return_ $return): bool
    {
        $method = $this->enclosingFunction($return);
        if (! $method instanceof Node\Stmt\ClassMethod || ! $method->isPublic() || $method->name->toString() === '__construct') {
            return false;
        }
        $class = $method->getAttribute('parent');

        return $class instanceof Node\Stmt\Class_ && $class->name !== null && str_ends_with($class->name->toString(), $this->config->controllerSuffix);
    }

    /**
     * Whether the class reaches the kit's ViewModel without passing PageViewModel or a `$standalone = true`, through the app's own classes.
     *
     * @param  array<string, Node\Stmt\Class_>  $classes
     */
    private function plainViewModel(string $name, array $classes): bool
    {
        for ($seen = []; isset($classes[$name]) && ! isset($seen[$name]); $name = ltrim($class->extends->toString(), '\\')) {
            $seen[$name] = true;
            $class = $classes[$name];
            if ($this->standalone($class) || $class->extends === null) {
                return false;
            }
            $parent = ltrim($class->extends->toString(), '\\');
            if (in_array($parent, self::PAGES, true)) {
                return false;
            }
            if ($parent === self::VIEW_MODEL) {
                return true;
            }
        }

        return false;
    }

    private function standalone(Node\Stmt\Class_ $class): bool
    {
        foreach ($class->getProperties() as $property) {
            // The kit's opt-out exactly: `protected bool $standalone = true`, not any property of that name.
            if (! $property->isProtected() || $property->isStatic() || ! $property->type instanceof Node\Identifier || $property->type->toLowerString() !== 'bool') {
                continue;
            }
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === 'standalone' && $prop->default instanceof Node\Expr\ConstFetch && strtolower($prop->default->name->toString()) === 'true') {
                    return true;
                }
            }
        }

        return false;
    }
}
