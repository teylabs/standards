<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use DateTimeImmutable;
use Pest\TestSuite;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;
use Tey\Standards\Patterns\PatternCatalog;
use ReflectionProperty;
use Tey\Standards\Ratchet;
use Throwable;

/**
 * Runs every registered conformance rule outside a Pest run and applies the
 * allowlist: unexcepted violations, stale exceptions, entries without an
 * expiry and expired entries are all drift. A rule that cannot run is drift
 * too, so the gate fails closed.
 */
final class Gate
{
    public const OPTIONAL = ['ui plus', 'ui new-tab', 'ui dropzone', 'ui toast'];

    public function __construct(
        private readonly Configuration $config,
        private readonly DateTimeImmutable $today,
        private readonly ?PatternCatalog $patterns = null,
        private readonly int $warnDays = 14,
    ) {}

    /**
     * @param  list<string>|null  $paths  project-relative paths to scope violations to; null checks everything
     * @return array{violations: list<array{rule: string, kind: string, target: string, check: string, hint: string}>, allowlist: array{count: int, expiring: list<array<string, string>>, expired: list<array<string, string>>}, skipped: list<string>}
     */
    public function run(?array $paths = null): array
    {
        $ratchet = new Ratchet($this->config->allowlist);
        $violations = [];
        $skipped = [];

        // Placement and preset rules read Pest's suite root; outside a Pest run it is the app.
        if (class_exists(TestSuite::class) && ! (new ReflectionProperty(TestSuite::class, 'instance'))->getValue() instanceof TestSuite) {
            TestSuite::getInstance($this->config->base, 'tests');
        }

        $conformance = new Conformance($this->config);

        foreach (array_keys(Conformance::TESTS) as $rule) {
            $rule = (string) $rule;

            if ((in_array($rule, self::OPTIONAL, true) && ! in_array($rule, $this->config->optionalRules, true)) || ($rule === '51' && ! $this->config->ciEnforce)) {
                $skipped[] = $rule;

                continue;
            }

            try {
                $actual = $conformance->violations($rule);
            } catch (Throwable $error) {
                $violations[] = $this->finding($rule, 'error', 'rule', 'could not run: '.$error->getMessage());

                continue;
            }

            $allowed = [];

            foreach ($ratchet->entries() as $entry) {
                if ($entry['rule'] === $rule) {
                    $allowed[$entry['target'].' | '.$entry['check']] = $entry;
                }
            }

            $seen = [];

            foreach ($actual as $violation) {
                $key = $violation['target'].' | '.$violation['check'];
                $seen[$key] = true;

                if (! isset($allowed[$key]) && $this->inScope($violation['target'], $paths)) {
                    $violations[] = $this->finding($rule, 'violation', $violation['target'], $violation['check']);
                }
            }

            foreach ($allowed as $key => $entry) {
                if (! isset($seen[$key]) && $this->inScope($entry['target'], $paths)) {
                    $violations[] = $this->finding($rule, 'stale', $entry['target'], $entry['check'], 'The debt is gone; delete this allowlist entry.');
                }
            }
        }

        $expiring = [];
        $expired = [];
        $soon = $this->today->modify('+'.$this->warnDays.' days')->format('Y-m-d');
        $today = $this->today->format('Y-m-d');

        foreach ($ratchet->entries() as $entry) {
            $summary = ['rule' => $entry['rule'], 'target' => $entry['target'], 'check' => $entry['check'], 'expires' => (string) $entry['expires']];

            if ($entry['expires'] === null) {
                $violations[] = $this->finding($entry['rule'], 'missing-expiry', $entry['target'], $entry['check'], 'Give this allowlist entry an `expires` Y-m-d date and a reason.');
            } elseif ($entry['expires'] < $today) {
                $expired[] = $summary;
                $violations[] = $this->finding($entry['rule'], 'expired', $entry['target'], $entry['check'], 'Expired '.$entry['expires'].': fix the debt, or extend `expires` with a new reason.');
            } elseif ($entry['expires'] <= $soon) {
                $expiring[] = $summary;
            }
        }

        return [
            'violations' => $violations,
            'allowlist' => ['count' => count($ratchet->entries()), 'expiring' => $expiring, 'expired' => $expired],
            'skipped' => $skipped,
        ];
    }

    /** @param list<string>|null $paths */
    private function inScope(string $target, ?array $paths): bool
    {
        if ($paths === null) {
            return true;
        }

        $file = $this->pathOf($target);

        foreach ($paths as $path) {
            if ($file === $path || str_starts_with($target, $path.' ') || str_starts_with($target, $path.':')) {
                return true;
            }
        }

        return false;
    }

    /** Targets are relative paths, sometimes with a suffix, or class names that map through classRoots. */
    private function pathOf(string $target): string
    {
        foreach ($this->config->classRoots as $root => $namespace) {
            $prefix = rtrim($namespace, '\\').'\\';

            if (str_starts_with($target, $prefix) && ! str_contains($target, '/')) {
                return rtrim($root, '/').'/'.str_replace('\\', '/', substr($target, strlen($prefix))).'.php';
            }
        }

        return $target;
    }

    /** @return array{rule: string, kind: string, target: string, check: string, hint: string} */
    private function finding(string $rule, string $kind, string $target, string $check, ?string $hint = null): array
    {
        return ['rule' => $rule, 'kind' => $kind, 'target' => $target, 'check' => $check, 'hint' => $hint ?? $this->hint($rule)];
    }

    private function hint(string $rule): string
    {
        $leaf = $this->patterns?->forRule($rule);

        if ($leaf !== null) {
            return 'Follow pattern '.$leaf->id.' ('.$leaf->summary.'); scaffold with `php artisan '.$leaf->make.'`. Run `php artisan standards:pattern '.$leaf->id.'`.';
        }

        return 'Fix the code, or add an allowlist entry with rule, target, check, reason and expires. See `php artisan standards:pattern "rule '.$rule.'"`.';
    }
}
