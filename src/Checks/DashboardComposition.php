<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `ui.dashboard-composition` (warn only; pattern leaf ui-page.dashboard-widget): the dashboard's widgets compose the way the kit lays them out.
 * Reads the app's subclasses of the kit's dashboard Widget and the literal values their `kind()` and `region()` return, and warns when:
 * - more than one widget is a 'feed';
 * - a widget sits in the wrong column for its kind: 'task' and 'feed' belong in 'main', 'status' and 'setup' in 'aside';
 * - a widget does not declare `kind()`.
 * A `kind()` or `region()` that returns anything but one string literal is reported as "can't verify", never as a fault.
 *
 * Every finding is advisory: the rule warns and is never enforced, so listing it in Configuration::$optionalRules changes nothing.
 */
final readonly class DashboardComposition
{
    public const RULE = 'ui.dashboard-composition';

    public const WIDGET = 'Tey\Kit\Features\SessionUi\Dashboard\Widget';

    /** The column each kind belongs in. */
    private const REGIONS = ['task' => 'main', 'feed' => 'main', 'status' => 'aside', 'setup' => 'aside'];

    private const HINT = 'Dashboard composition (ui-page.dashboard-widget): one feed at most; task and feed widgets in main, status and setup widgets in aside; every widget declares kind().';

    public function __construct(private Configuration $config, private Source $source) {}

    /** @return list<array{target: string, check: string, line: int, hint: string, advisory: true}> */
    public function violations(): array
    {
        $finder = new NodeFinder;
        $found = [];
        $feeds = [];
        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            if (! str_contains($text, 'Widget')) {
                continue;
            }
            foreach ($finder->findInstanceOf($this->source->nodes($path), Node\Stmt\Class_::class) as $class) {
                if ($class->extends === null || ltrim($class->extends->toString(), '\\') !== self::WIDGET || $class->isAbstract()) {
                    continue;
                }
                $name = $class->namespacedName?->toString() ?? (string) $class->name;
                $line = $class->getStartLine();
                $kind = $this->literal($class, 'kind');
                $region = $this->literal($class, 'region');
                if ($kind === null) {
                    $found[] = $this->finding($path, $line, "{$name} does not declare kind(): say what the widget is (task, feed, status or setup)");

                    continue;
                }
                if ($kind === false) {
                    $found[] = $this->finding($path, $line, "can't verify {$name}: kind() does not return one string literal");

                    continue;
                }
                if ($kind === 'feed') {
                    $feeds[] = [$path, $line, $name];
                }
                $expected = self::REGIONS[$kind] ?? null;
                if ($expected === null) {
                    continue;
                }
                if ($region === false) {
                    $found[] = $this->finding($path, $line, "can't verify {$name}: region() does not return one string literal");
                } elseif ($region !== null && $region !== $expected) {
                    $found[] = $this->finding($path, $line, "{$name} is a '{$kind}' widget in '{$region}': {$kind} widgets go in '{$expected}'");
                }
            }
        }
        if (count($feeds) > 1) {
            foreach ($feeds as [$path, $line, $name]) {
                $found[] = $this->finding($path, $line, "{$name} is one of ".count($feeds).' feed widgets: a dashboard has one feed at most');
            }
        }

        return $found;
    }

    /** The string literal a method returns: null when the class does not declare it, false when it returns anything else. */
    private function literal(Node\Stmt\Class_ $class, string $method): string|false|null
    {
        $declared = $class->getMethod($method);
        if ($declared === null) {
            return null;
        }
        $returns = (new NodeFinder)->findInstanceOf($declared->stmts ?? [], Node\Stmt\Return_::class);
        $values = array_unique(array_map(fn (Node\Stmt\Return_ $return): string|false => $return->expr instanceof Node\Scalar\String_ ? $return->expr->value : false, $returns));

        return count($values) === 1 && $values[0] !== false ? $values[0] : false;
    }

    /** @return array{target: string, check: string, line: int, hint: string, advisory: true} */
    private function finding(string $path, int $line, string $check): array
    {
        return ['target' => $path, 'check' => $check, 'line' => $line, 'hint' => self::HINT, 'advisory' => true];
    }
}
