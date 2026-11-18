<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * `ui.row-actions-declared` (warn only): a table's row actions declare their shape (kit 0.29+, TableQuery::rowActions()).
 *
 * Read per class method or function that builds a TableQuery (`new TableQuery`, `TableQuery::…()`) and gives its rows actions (an `'actions' =>` key, or an
 * Action made in the method):
 * - with no `->rowActions(primary: …)` or `->rowActions(variant: …)` call (nor a positional variant), no Action with `priority: 'primary'`, and no `:row-actions`
 *   on the `<DataTable>` of a page the class renders, RowActions infers a shape row by row, so one column can mix shapes;
 * - with a literal `primary` that no literal Action key (`key:`) in the method's class matches, every row shows its menu alone, almost always a typo.
 *   Skipped when any Action key in the class is not a literal.
 * Not covered: Workbench and ScrollList row menus (no TableQuery), and rows or actions built in another class.
 */
final class RowActionsDeclared
{
    public const RULE = 'ui.row-actions-declared';

    /** @return list<array{target: string, check: string, line: int, hint: string}> */
    public static function violations(Configuration $config): array
    {
        $source = new Source($config);
        $finder = new NodeFinder;
        $found = [];
        foreach ($source->files($config->sourceRoots) as $path => $text) {
            if (! str_contains($text, 'TableQuery')) {
                continue;
            }
            $nodes = $source->nodes($path);
            $pageDeclares = self::pageDeclares($config, $finder, $nodes);
            // Each method is read with its class: row actions are often built in a helper method of the same class (a private actions($row)).
            $scopes = [];
            foreach ($finder->find($nodes, fn (Node $node): bool => $node instanceof Node\Stmt\ClassLike) as $class) {
                foreach ($class->getMethods() as $method) {
                    $scopes[] = [$method, $class->stmts];
                }
            }
            foreach ($finder->find($nodes, fn (Node $node): bool => $node instanceof Node\Stmt\Function_) as $function) {
                $scopes[] = [$function, $function->stmts];
            }
            foreach ($scopes as [$scope, $classStmts]) {
                $query = $finder->findFirst($scope->stmts ?? [], fn (Node $node): bool => ($node instanceof Node\Expr\New_ || $node instanceof Node\Expr\StaticCall)
                    && $node->class instanceof Node\Name && $node->class->getLast() === 'TableQuery');
                if ($query === null) {
                    continue;
                }
                $actions = $finder->find($scope->stmts ?? [], fn (Node $node): bool => ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name && $node->class->getLast() === 'Action')
                    || ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->class->getLast() === 'Action' && $node->name instanceof Node\Identifier && in_array($node->name->toLowerString(), ['make', 'disabled'], true)));
                $actionsKey = $finder->findFirst($scope->stmts ?? [], fn (Node $node): bool => $node instanceof Node\ArrayItem && $node->key instanceof Node\Scalar\String_ && $node->key->value === 'actions');
                if ($actions === [] && $actionsKey === null) {
                    continue;
                }
                $calls = $finder->find($scope->stmts ?? [], fn (Node $node): bool => $node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier && $node->name->toLowerString() === 'rowactions');
                $primary = null;
                $declared = false;
                foreach ($calls as $call) {
                    foreach ($call->getArgs() as $index => $argument) {
                        $name = $argument->name?->toString() ?? ($index === 0 ? 'variant' : ($index === 1 ? 'primary' : 'trigger'));
                        if ($name === 'trigger' || ($argument->value instanceof Node\Expr\ConstFetch && $argument->value->name->toLowerString() === 'null')) {
                            continue;
                        }
                        $declared = true;
                        if ($name === 'primary') {
                            $primary = $argument->value instanceof Node\Scalar\String_ ? $argument->value->value : false;
                        }
                    }
                }
                $keys = [];
                $dynamicKey = false;
                // Keys are read across the class, so a primary built in a helper method is found; `priority: 'primary'` still counts only in this method.
                $classActions = $finder->find($classStmts, fn (Node $node): bool => ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name && $node->class->getLast() === 'Action')
                    || ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->class->getLast() === 'Action' && $node->name instanceof Node\Identifier && in_array($node->name->toLowerString(), ['make', 'disabled'], true)));
                foreach ($actions as $action) {
                    foreach ($action->getArgs() as $argument) {
                        if ($argument->name?->toString() === 'priority' && $argument->value instanceof Node\Scalar\String_ && $argument->value->value === 'primary') {
                            $declared = true;
                        }
                    }
                }
                foreach ($classActions as $action) {
                    foreach ($action->getArgs() as $index => $argument) {
                        $name = $argument->name?->toString();
                        if ($name === 'key' || ($name === null && $index === 6 && $action instanceof Node\Expr\New_)) {
                            $argument->value instanceof Node\Scalar\String_ ? $keys[] = $argument->value->value : $dynamicKey = true;
                        }
                    }
                }
                $line = $query->getStartLine();
                if (! $declared && ! $pageDeclares) {
                    $found[] = ['target' => $path, 'check' => 'table row actions declare no shape: RowActions infers one row by row', 'line' => $line,
                        'hint' => "Name each row's primary action, ->rowActions(primary: '<action key>'), or put them all in the menu, ->rowActions(variant: 'menu'), so every row in the column takes the same shape (kit 0.29+)."];
                } elseif (is_string($primary) && $classActions !== [] && ! $dynamicKey && ! in_array($primary, $keys, true)) {
                    $found[] = ['target' => $path, 'check' => "row actions primary '{$primary}' matches no action key", 'line' => $line,
                        'hint' => "No Action in this class has key: '{$primary}', so every row shows its menu alone. Give the primary Action that key, or name the key it has".($keys === [] ? '.' : ' ('.implode(', ', array_unique($keys)).').')];
                }
            }
        }

        return $found;
    }

    /** Whether a page this file renders (Inertia::render('X'), inertia('X')) passes `:row-actions` or `row-actions` to its DataTable: the page wins. */
    private static function pageDeclares(Configuration $config, NodeFinder $finder, array $nodes): bool
    {
        $renders = $finder->find($nodes, fn (Node $node): bool => (($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->class->getLast() === 'Inertia' && $node->name instanceof Node\Identifier && $node->name->toLowerString() === 'render')
            || ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && $node->name->toLowerString() === 'inertia'))
            && isset($node->getArgs()[0]) && $node->getArgs()[0]->value instanceof Node\Scalar\String_);
        foreach ($renders as $render) {
            $page = $render->getArgs()[0]->value->value;
            foreach (['resources/js/pages/'.$page.'.vue', 'resources/js/pages/'.$page.'/Page.vue', 'resources/js/Pages/'.$page.'.vue'] as $file) {
                $text = is_file($config->base.'/'.$file) ? (string) file_get_contents($config->base.'/'.$file) : '';
                if (preg_match('~<DataTable\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*\s(?::|v-bind:)?row-actions\s*=~', $text) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
