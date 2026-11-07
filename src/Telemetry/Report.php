<?php

declare(strict_types=1);

namespace Tey\Standards\Telemetry;

/** Aggregates episodes into the first-pass report: rate, corrections per episode, most-tripped rules, consulted versus not. */
final class Report
{
    public const GROUPS = ['rule', 'agent', 'pattern'];

    /**
     * @param  list<array<string, mixed>>  $episodes
     * @param  list<array{line: int, reason: string}>  $breaks
     * @return array<string, mixed>
     */
    public static function build(array $episodes, ?string $since = null, ?string $source = null, ?string $by = null, array $breaks = []): array
    {
        $sinceTime = $since === null ? null : strtotime($since);
        $episodes = array_values(array_filter($episodes, fn (array $episode): bool => ($source === null || $episode['source'] === $source)
            && ($sinceTime === null || strtotime((string) $episode['opened_at']) >= $sinceTime)));

        $report = [
            'contract' => 1,
            'since' => $since,
            'source' => $source,
            ...self::summary($episodes),
            'rulesets' => array_count_values(array_map(fn (array $episode): string => (string) ($episode['ruleset'] ?? 'unknown'), $episodes)),
            'consulted' => [
                'yes' => self::summary(array_values(array_filter($episodes, fn (array $episode): bool => $episode['consulted'] === true))),
                'no' => self::summary(array_values(array_filter($episodes, fn (array $episode): bool => $episode['consulted'] === false))),
                'unknown' => count(array_filter($episodes, fn (array $episode): bool => $episode['consulted'] === null)),
                'note' => 'Correlation, not causation: consulting the tree and passing first time may share a cause.',
            ],
            'chain_breaks' => $breaks,
        ];

        if ($by !== null) {
            $report['by'] = $by;
            $report['groups'] = self::groups($episodes, $by);
        }

        return $report;
    }

    /**
     * @param  list<array<string, mixed>>  $episodes
     * @return array{episodes: int, first_pass: int, first_pass_rate: ?float, corrected: int, open: int, abandoned: int, corrections_per_episode: ?float, rules: array<string, int>}
     */
    public static function summary(array $episodes): array
    {
        $first = count(array_filter($episodes, fn (array $episode): bool => $episode['status'] === 'first-pass'));
        $closed = array_values(array_filter($episodes, fn (array $episode): bool => in_array($episode['status'], ['first-pass', 'corrected'], true)));
        $rules = [];

        foreach ($episodes as $episode) {
            foreach ($episode['first_pass'] as $rule => $count) {
                $rules[$rule] = ($rules[$rule] ?? 0) + $count;
            }
        }

        arsort($rules);

        return [
            'episodes' => count($episodes),
            'first_pass' => $first,
            'first_pass_rate' => $episodes === [] ? null : round($first / count($episodes), 3),
            'corrected' => count(array_filter($episodes, fn (array $episode): bool => $episode['status'] === 'corrected')),
            'open' => count(array_filter($episodes, fn (array $episode): bool => $episode['status'] === 'open')),
            'abandoned' => count(array_filter($episodes, fn (array $episode): bool => $episode['status'] === 'abandoned')),
            'corrections_per_episode' => $closed === [] ? null : round(array_sum(array_map(fn (array $episode): int => $episode['runs'] - 1, $closed)) / count($closed), 2),
            'rules' => $rules,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $episodes
     * @return array<string, array<string, mixed>>
     */
    private static function groups(array $episodes, string $by): array
    {
        $groups = [];

        foreach ($episodes as $episode) {
            $keys = match ($by) {
                'agent' => [$episode['agent']],
                'pattern' => $episode['patterns'] === [] ? ['none'] : $episode['patterns'],
                'rule' => array_keys($episode['first_pass']) ?: ['(clean)'],
            };

            foreach ($keys as $key) {
                $groups[(string) $key][] = $episode;
            }
        }

        ksort($groups);

        return array_map(fn (array $members): array => self::summary($members), $groups);
    }

    /** @param array<string, mixed> $report */
    public static function text(array $report): string
    {
        $rate = fn (?float $value): string => $value === null ? 'n/a' : number_format($value * 100, 1).'%';
        $lines = [sprintf('First pass%s%s: %d of %d episodes clean on the first gate run (%s); %d corrected, %d open, %d abandoned; %s corrections per closed episode.',
            $report['source'] === null ? '' : ' ('.$report['source'].')', $report['since'] === null ? '' : ' since '.$report['since'],
            $report['first_pass'], $report['episodes'], $rate($report['first_pass_rate']), $report['corrected'], $report['open'], $report['abandoned'],
            $report['corrections_per_episode'] === null ? 'n/a' : (string) $report['corrections_per_episode'])];

        if ($report['rules'] !== []) {
            $lines[] = 'Most-tripped rules: '.implode(', ', array_map(fn (string $rule, int $count): string => "{$rule} ({$count})", array_keys(array_slice($report['rules'], 0, 5, true)), array_slice($report['rules'], 0, 5, true)));
        }

        if (count($report['rulesets']) > 1) {
            $lines[] = 'Rulesets: '.implode(', ', array_map(fn (string $hash, int $count): string => "{$hash} ({$count})", array_keys($report['rulesets']), $report['rulesets'])).'. Compare rates within one ruleset.';
        }

        $consulted = $report['consulted'];
        $lines[] = sprintf('Consulted the tree: %s first pass (n=%d); did not: %s (n=%d); unknown: %d. %s',
            $rate($consulted['yes']['first_pass_rate']), $consulted['yes']['episodes'], $rate($consulted['no']['first_pass_rate']), $consulted['no']['episodes'], $consulted['unknown'], $consulted['note']);

        foreach ($report['groups'] ?? [] as $key => $group) {
            $lines[] = sprintf('  %s: %s first pass (n=%d), %s corrections per closed episode', $key, $rate($group['first_pass_rate']), $group['episodes'], $group['corrections_per_episode'] ?? 'n/a');
        }

        foreach ($report['chain_breaks'] as $break) {
            $lines[] = "Chain break at line {$break['line']}: {$break['reason']}.";
        }

        return implode("\n", $lines);
    }
}
