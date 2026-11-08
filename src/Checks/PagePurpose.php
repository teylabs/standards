<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `ui.page-purpose` (warn only; step 1 of the page method in ui-page.own): every page states who opens it and their main job, in one line on the
 * page ViewModel's class docblock: `@purpose <who>: <main job>` (`@purpose Staff: watch unsigned agreements; send one occasionally.`). Warns on a
 * concrete class extending the kit's PageViewModel (or AdHocViewModel) with no `@purpose` tag, or one not shaped `<who>: <job>`. Abstract bases are
 * skipped. Every finding is advisory: the rule is never enforced, and listing it in Configuration::$optionalRules changes nothing.
 */
final readonly class PagePurpose
{
    public const RULE = 'ui.page-purpose';

    public const BASES = ['Tey\Kit\Features\Core\ViewModels\PageViewModel', 'Tey\Kit\Features\Core\ViewModels\AdHocViewModel'];

    /** `@purpose`, then who (no colon), a colon, and the job. */
    private const SHAPE = '~^\s*(?:/\*\*)?\s*\*?\s*@purpose[ \t]+([^:\n]*\S)[ \t]*:[ \t]*(?!\*/)(\S[^\n]*?)\s*(?:\*/)?\s*$~m';

    private const HINT = 'State the page purpose first (ui-page.own, step 1): one line on the ViewModel\'s class docblock, `@purpose <who>: <main job>`, e.g. `@purpose Staff: watch unsigned agreements; send one occasionally.`';

    public function __construct(private Configuration $config, private Source $source) {}

    /** @return list<array{target: string, check: string, line: int, hint: string, advisory: true}> */
    public function violations(): array
    {
        // Every app class and its parent, so a page extending the app's own (abstract) base still counts.
        $classes = [];
        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            foreach ((new NodeFinder)->findInstanceOf($this->source->nodes($path), Node\Stmt\Class_::class) as $class) {
                if ($class->name !== null) {
                    $classes[$class->namespacedName?->toString() ?? (string) $class->name] = [$path, $class];
                }
            }
        }
        $found = [];
        foreach ($classes as [$path, $class]) {
            if ($class->isAbstract() || ! $this->isPage($class, $classes)) {
                continue;
            }
            $name = $class->namespacedName?->toString() ?? (string) $class->name;
            $doc = (string) $class->getDocComment()?->getText();
            if (preg_match('~@purpose\b~', $doc) !== 1) {
                $found[] = $this->finding($path, $class->getStartLine(), "{$name} does not state its purpose: add `@purpose <who>: <main job>` to its class docblock");
            } elseif (preg_match(self::SHAPE, $doc) !== 1) {
                $found[] = $this->finding($path, $class->getStartLine(), "{$name}'s @purpose is not shaped `<who>: <main job>`");
            }
        }

        return $found;
    }

    /**
     * Whether the class descends from a kit page base, through the app's own classes.
     *
     * @param  array<string, array{0: string, 1: Node\Stmt\Class_}>  $classes
     */
    private function isPage(Node\Stmt\Class_ $class, array $classes): bool
    {
        for ($seen = []; $class->extends !== null; $class = $classes[$parent][1]) {
            $parent = ltrim($class->extends->toString(), '\\');
            if (in_array($parent, self::BASES, true)) {
                return true;
            }
            if (! isset($classes[$parent]) || isset($seen[$parent])) {
                return false;
            }
            $seen[$parent] = true;
        }

        return false;
    }

    /** @return array{target: string, check: string, line: int, hint: string, advisory: true} */
    private function finding(string $path, int $line, string $check): array
    {
        return ['target' => $path, 'check' => $check, 'line' => $line, 'hint' => self::HINT, 'advisory' => true];
    }
}
