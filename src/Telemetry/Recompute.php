<?php

declare(strict_types=1);

namespace Tey\Standards\Telemetry;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tey\Standards\Source;

/**
 * The milestone cross-check: replays the gate on each commit of a git range in a clean worktree, rebuilds commit-level episodes from
 * history, and compares each commit's files with the run log. A mismatch is a committed file the log never saw gated, a file whose
 * committed content differs from the last content the log gated, or a file the log last saw clean that the replay finds findings in.
 */
final class Recompute
{
    /** @var Closure(string, list<string>): array<string, mixed> */
    private readonly Closure $gate;

    /** @param (Closure(string, list<string>): array<string, mixed>)|null $gate runs the gate in a worktree on files and returns the schema-1 report */
    public function __construct(private readonly string $base, ?Closure $gate = null, private readonly string $configurationFile = 'tests/Arch/configuration.php')
    {
        $this->gate = $gate ?? self::artisanGate(...);
    }

    /**
     * @param  list<array<string, mixed>>  $log  the run log's records
     * @param  list<array{line: int, reason: string}>  $breaks
     * @return array<string, mixed>
     */
    public function run(string $range, array $log, array $breaks = []): array
    {
        if (! str_contains($range, '..')) {
            throw new RuntimeException("--recompute takes a git range such as v1.0..HEAD, not {$range}.");
        }

        $commits = array_values(array_filter(explode("\n", trim((string) Diff::git($this->base, ['rev-list', '--reverse', $range])))));

        if ($commits === []) {
            throw new RuntimeException("No commits in {$range}.");
        }

        $worktree = sys_get_temp_dir().'/standards-recompute-'.bin2hex(random_bytes(6));
        $this->mustGit($this->base, ['worktree', 'add', '--detach', '--quiet', $worktree, $commits[0]]);

        try {
            $this->prepare($worktree);
            $records = [];

            foreach ($commits as $commit) {
                $this->mustGit($worktree, ['checkout', '--quiet', '--force', '--detach', $commit]);
                // Only files the gate can check: a docs or changelog commit is not an episode, so it is never a mismatch.
                $files = array_values(array_filter(explode("\n", trim((string) Diff::git($worktree, ['diff-tree', '--root', '--no-commit-id', '--name-only', '-r', '--diff-filter=d', $commit]))), Source::gated(...)));
                $parent = Diff::git($worktree, ['rev-parse', '--verify', '--quiet', $commit.'^']);
                $report = $files === [] ? ['violations' => []] : ($this->gate)($worktree, $files);
                $recorder = new Recorder($worktree, false, $this->configurationFile);
                $records[] = [
                    'kind' => 'run',
                    'ts' => date('Y-m-d\TH:i:sP', (int) trim((string) Diff::git($worktree, ['show', '-s', '--format=%ct', $commit]))),
                    'source' => 'recompute',
                    'scope' => 'file',
                    'sha' => $commit,
                    'files' => $recorder->files($files, Diff::lines($worktree, $files, $parent === null ? null : trim($parent), $commit), (array) ($report['violations'] ?? [])),
                ];
            }
        } finally {
            Diff::git($this->base, ['worktree', 'remove', '--force', $worktree]);
        }

        return [
            'range' => $range,
            'commits' => count($commits),
            'episodes' => Report::summary(Episodes::build($records)),
            'mismatches' => self::compare($records, $log),
            'chain_breaks' => $breaks,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $replayed
     * @param  list<array<string, mixed>>  $log
     * @return list<array{sha: string, path: string, reason: string}>
     */
    public static function compare(array $replayed, array $log): array
    {
        $runs = array_values(array_filter($log, fn (array $record): bool => ($record['kind'] ?? null) === 'run' && ($record['source'] ?? 'local') === 'local'));
        $mismatches = [];

        foreach ($replayed as $commit) {
            $at = strtotime((string) $commit['ts']);

            foreach ($commit['files'] as $file) {
                $last = null;

                foreach ($runs as $run) {
                    if (strtotime((string) ($run['ts'] ?? '')) > $at) {
                        continue;
                    }

                    foreach ((array) ($run['files'] ?? []) as $entry) {
                        if (is_array($entry) && ($entry['path'] ?? null) === $file['path']) {
                            $last = $entry;
                        }
                    }
                }

                $dirty = array_sum($file['findings']) > 0;
                $reason = match (true) {
                    $last === null => 'committed but never gated in the log',
                    ($last['hash'] ?? null) !== $file['hash'] => 'committed content differs from the last content the log gated',
                    array_sum((array) ($last['findings'] ?? [])) === 0 && $dirty => 'the log saw it clean; the replay finds '.implode(', ', array_keys($file['findings'])),
                    default => null,
                };

                if ($reason !== null) {
                    $mismatches[] = ['sha' => (string) $commit['sha'], 'path' => (string) $file['path'], 'reason' => $reason];
                }
            }
        }

        return $mismatches;
    }

    /** The worktree gets its own copy of vendor, with the autoloader rebuilt there, so classes load from the commit, not the checkout. */
    private function prepare(string $worktree): void
    {
        if (! is_dir($this->base.'/vendor') || is_dir($worktree.'/vendor')) {
            return;
        }

        (new Process(['cp', '-R', $this->base.'/vendor', $worktree.'/vendor']))->setTimeout(600)->run();
        (new Process(['composer', 'dump-autoload', '--quiet', '--no-scripts'], $worktree))->setTimeout(300)->run();
    }

    /**
     * @param  list<string>  $files
     * @return array<string, mixed>
     */
    private static function artisanGate(string $worktree, array $files): array
    {
        $process = new Process([PHP_BINARY, 'artisan', 'standards:check', ...array_map(fn (string $file): string => '--file='.$file, $files), '--json'], $worktree, ['STANDARDS_TELEMETRY_FIRST_PASS' => 'false'], timeout: 600);
        $process->run();
        $report = json_decode($process->getOutput(), true);

        return is_array($report) ? $report : ['violations' => [['rule' => 'standards', 'kind' => 'error', 'target' => $files[0], 'check' => 'the replayed gate exited '.$process->getExitCode().' without a report']]];
    }

    /** @param list<string> $arguments */
    private function mustGit(string $directory, array $arguments): void
    {
        $process = new Process(['git', ...$arguments], $directory);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('git '.implode(' ', $arguments).' failed: '.trim($process->getErrorOutput()));
        }
    }
}
