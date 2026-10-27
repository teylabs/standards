<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use InvalidArgumentException;

/**
 * Aggregates several apps' `standards:check --json` reports into one drift
 * report, with regressions (findings new since the previous report) and fixes.
 * Delivery is the caller's job.
 */
final class DriftReport
{
    /**
     * @param  list<array<string, mixed>>  $reports
     * @param  array<string, mixed>|null  $previous  an earlier aggregate()
     * @return array<string, mixed>
     */
    public static function aggregate(array $reports, ?array $previous = null, ?string $generatedAt = null): array
    {
        $before = [];

        foreach ($previous['apps'] ?? [] as $app) {
            $before[$app['app']] = $app['keys'] ?? [];
        }

        $apps = [];

        foreach ($reports as $report) {
            if (($report['schema'] ?? null) !== 1 || ! is_string($report['app'] ?? null) || ! is_array($report['violations'] ?? null)) {
                throw new InvalidArgumentException('Each report must be standards:check --json output with schema 1.');
            }

            $keys = array_values(array_unique(array_map(fn (array $v): string => implode(' | ', [$v['rule'], $v['kind'], $v['target'], $v['check']]), $report['violations'])));
            sort($keys);
            $old = $before[$report['app']] ?? null;

            $apps[] = [
                'app' => $report['app'],
                'sha' => $report['sha'] ?? null,
                'violations' => count($keys),
                'allowlist' => $report['allowlist']['count'] ?? 0,
                'expiring' => count($report['allowlist']['expiring'] ?? []),
                'expired' => count($report['allowlist']['expired'] ?? []),
                'gaps' => $report['gaps'] ?? ['catalog' => 0, 'flagged' => 0],
                'regressions' => $old === null ? [] : array_values(array_diff($keys, $old)),
                'fixed' => $old === null ? [] : array_values(array_diff($old, $keys)),
                'first_run' => $old === null,
                'keys' => $keys,
            ];
        }

        usort($apps, fn (array $a, array $b): int => strcmp($a['app'], $b['app']));

        return [
            'schema' => 1,
            'generated_at' => $generatedAt ?? gmdate('Y-m-d\TH:i:s\Z'),
            'apps' => $apps,
            'totals' => [
                'apps' => count($apps),
                'violations' => array_sum(array_column($apps, 'violations')),
                'regressions' => array_sum(array_map(fn (array $app): int => count($app['regressions']), $apps)),
                'fixed' => array_sum(array_map(fn (array $app): int => count($app['fixed']), $apps)),
                'expired' => array_sum(array_column($apps, 'expired')),
                'flagged_gaps' => array_sum(array_map(fn (array $app): int => (int) ($app['gaps']['flagged'] ?? 0), $apps)),
                'gap_demand' => array_sum(array_map(fn (array $app): int => (int) ($app['gaps']['demand']['total'] ?? 0), $apps)),
            ],
        ];
    }

    /** @param array<string, mixed> $report */
    public static function text(array $report): string
    {
        $lines = ['Drift report '.$report['generated_at'], ''];

        foreach ($report['apps'] as $app) {
            $lines[] = sprintf('%s @ %s: %d violations, %d allowlisted (%d expiring, %d expired), %d flagged gaps, %d gap-demand lookups%s',
                $app['app'], substr((string) $app['sha'], 0, 12), $app['violations'], $app['allowlist'], $app['expiring'], $app['expired'], (int) ($app['gaps']['flagged'] ?? 0), (int) ($app['gaps']['demand']['total'] ?? 0),
                $app['first_run'] ? ', first run' : sprintf(', %d new, %d fixed', count($app['regressions']), count($app['fixed'])));

            foreach ($app['regressions'] as $key) {
                $lines[] = '  + '.$key;
            }
        }

        $totals = $report['totals'];
        $lines[] = '';
        $lines[] = sprintf('%d apps, %d violations, %d regressions, %d fixed, %d expired allowlist entries.', $totals['apps'], $totals['violations'], $totals['regressions'], $totals['fixed'], $totals['expired']);

        return implode("\n", $lines)."\n";
    }
}
