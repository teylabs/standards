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
    private const SHAPE = '~^\s*\*?\s*@purpose[ \t]+([^:\n]*\S)[ \t]*:[ \t]*(\S[^\n]*)$~m';

    private const HINT = 'State the page purpose first (ui-page.own, step 1): one line on the ViewModel\'s class docblock, `@purpose <who>: <main job>`, e.g. `@purpose Staff: watch unsigned agreements; send one occasionally.`';

    public function __construct(private Configuration $config, private Source $source) {}

    /** @return list<array{target: string, check: string, line: int, hint: string, advisory: true}> */
    public function violations(): array
    {
        $found = [];
        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            if (! str_contains($text, 'ViewModel')) {
                continue;
            }
            foreach ((new NodeFinder)->findInstanceOf($this->source->nodes($path), Node\Stmt\Class_::class) as $class) {
                if ($class->extends === null || $class->isAbstract() || ! in_array(ltrim($class->extends->toString(), '\\'), self::BASES, true)) {
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
        }

        return $found;
    }

    /** @return array{target: string, check: string, line: int, hint: string, advisory: true} */
    private function finding(string $path, int $line, string $check): array
    {
        return ['target' => $path, 'check' => $check, 'line' => $line, 'hint' => self::HINT, 'advisory' => true];
    }
}
