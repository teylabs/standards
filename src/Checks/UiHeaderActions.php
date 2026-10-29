<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * A page header shows at most one primary action and three visible ones (the kit's headerSplit: one primary, up to two secondaries, the rest in More).
 * Two shapes are read. A page ViewModel (a class extending the kit's PageViewModel or WorkbenchViewModel) whose `actions()` or `pageActions()` returns an
 * array literal of `Action` descriptors: more than one `priority: 'primary'`, more than three primary or secondary ones, or more than three with no
 * priority at all (the split folds the rest into More, unasked). And a `<template #actions>` slot holding more than three literal action controls,
 * which bypasses the split. Advisory: only literal arrays and slots are counted, so actions built in a loop or helper are never flagged.
 */
final class UiHeaderActions
{
    public const VIEW_MODELS = ['PageViewModel', 'WorkbenchViewModel'];

    public const METHODS = ['actions', 'pageActions'];

    public const MAX_VISIBLE = 3;

    /** @return list<array{target: string, check: string}> */
    public function violations(Configuration $config, ?Source $source = null): array
    {
        $violations = [];
        $source ??= new Source($config);
        foreach ($source->files($config->sourceRoots) as $path => $text) {
            if (! str_contains($text, 'ViewModel')) {
                continue;
            }
            foreach ((new NodeFinder)->findInstanceOf($source->nodes($path), Node\Stmt\Class_::class) as $class) {
                if ($class->extends === null || ! in_array($class->extends->getLast(), self::VIEW_MODELS, true)) {
                    continue;
                }
                foreach ($class->getMethods() as $method) {
                    if (! in_array($method->name->toString(), self::METHODS, true)) {
                        continue;
                    }
                    foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Node\Stmt\Return_::class) as $return) {
                        if ($return->expr instanceof Node\Expr\Array_ && ($check = self::arrayCheck($return->expr)) !== null) {
                            $violations[] = ['target' => $path, 'check' => $check];
                        }
                    }
                }
            }
        }
        foreach ((new FrontendSource($config))->appFiles() as $path => $text) {
            if (str_ends_with($path, '.vue') && self::slotControls($text, $config) > self::MAX_VISIBLE) {
                $violations[] = ['target' => $path, 'check' => 'header #actions slot holds more than 3 controls; pass descriptors to Page :actions'];
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    private static function arrayCheck(Node\Expr\Array_ $array): ?string
    {
        $priorities = [];
        foreach ($array->items as $item) {
            if ($item === null || ! self::isAction($item->value)) {
                continue;
            }
            $priorities[] = self::priority($item->value);
        }
        $count = array_count_values(array_map(fn (?string $priority): string => $priority ?? '', $priorities));
        if (($count['primary'] ?? 0) > 1) {
            return 'header actions declare more than one primary';
        }
        if (($count['primary'] ?? 0) + ($count['secondary'] ?? 0) > self::MAX_VISIBLE) {
            return 'header actions show more than 3; mark the rest overflow';
        }
        if (count($priorities) > self::MAX_VISIBLE && ($count[''] ?? 0) === count($priorities)) {
            return 'more than 3 header actions with no priority; set priority to choose the visible ones';
        }

        return null;
    }

    private static function isAction(Node\Expr $expr): bool
    {
        return ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name && $expr->class->getLast() === 'Action')
            || ($expr instanceof Node\Expr\StaticCall && $expr->class instanceof Node\Name && $expr->class->getLast() === 'Action');
    }

    private static function priority(Node\Expr $expr): ?string
    {
        foreach ($expr instanceof Node\Expr\New_ || $expr instanceof Node\Expr\StaticCall ? $expr->args : [] as $arg) {
            if ($arg instanceof Node\Arg && $arg->name?->toString() === 'priority' && $arg->value instanceof Node\Scalar\String_) {
                return $arg->value->value;
            }
        }

        return null;
    }

    private static function slotControls(string $source, Configuration $config): int
    {
        $template = FrontendSource::template($source);
        if (! preg_match('~<template\s+(?:#actions|v-slot:actions)\b[^>]*>(.*?)</template>~s', $template, $slot)) {
            return 0;
        }

        return count(FrontendSource::actions('<template>'.$slot[1].'</template>', $config));
    }
}
