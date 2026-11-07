<?php

declare(strict_types=1);

namespace Tey\Standards\Telemetry;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;
use Tey\Standards\Enforcement\ChangedFiles;
use Tey\Standards\Enforcement\CheckReport;
use Tey\Standards\Patterns\TreeCatalog;

final class FirstPassCommand extends Command
{
    public $signature = 'standards:first-pass {--since= : Only episodes opened on or after this date} {--by= : Group by rule, agent or pattern} {--source= : ci or local} {--log=* : Read these run logs or CI artifacts (JSON lines) instead of storage/standards/runs.jsonl (repeatable)} {--recompute= : Replay the gate per commit of this git range in a clean worktree and compare with the log} {--ci : Write this commit\'s per-file first-pass findings as a CI artifact and step summary; never fails} {--out=first-pass.jsonl : With --ci, the artifact file} {--base= : With --ci, the ref changed files compare against} {--json : Write JSON to stdout}';

    public $description = 'First-pass rate of gate runs: how often a changed file passes standards:check on the first run';

    public function handle(Repository $config): int
    {
        $base = $this->laravel->basePath();
        $configurationFile = (string) $config->get('standards.configuration', 'tests/Arch/configuration.php');

        if ($this->option('ci')) {
            return $this->ci($base, $configurationFile, $config);
        }

        $by = $this->option('by');
        $source = $this->option('source');

        if (($by !== null && ! in_array($by, Report::GROUPS, true)) || ($source !== null && ! in_array($source, ['ci', 'local'], true))) {
            $this->error('--by takes rule, agent or pattern; --source takes ci or local.');

            return 2;
        }

        $records = [];
        $breaks = [];
        $logs = array_values(array_filter((array) $this->option('log'), 'is_string')) ?: [$base.'/'.RunLog::FILE];

        foreach ($logs as $log) {
            $read = RunLog::read(str_starts_with($log, '/') ? $log : $base.'/'.$log);
            $records = [...$records, ...$read['records']];
            $breaks = [...$breaks, ...array_map(fn (array $break): array => [...$break, 'log' => $log], $read['breaks'])];
        }

        // Episodes read records in order: merged logs are sorted by timestamp.
        if (count($logs) > 1) {
            $records = self::chronological($records);
        }

        if (is_string($range = $this->option('recompute'))) {
            try {
                $result = (new Recompute($base, configurationFile: $configurationFile))->run($range, $records, $breaks);
            } catch (RuntimeException $error) {
                $this->error($error->getMessage());

                return 2;
            }

            $this->line($this->option('json') ? json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : self::recomputeText($result));

            return $result['mismatches'] === [] && $result['chain_breaks'] === [] ? 0 : 1;
        }

        $report = Report::build(Episodes::build($records), $this->option('since'), $source, $by, $breaks);
        $this->line($this->option('json') ? json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : Report::text($report));

        return 0;
    }

    /** CI mode: the gate on the changed files of this commit, recorded as one run record, plus a step summary. It never fails the job. */
    private function ci(string $base, string $configurationFile, Repository $config): int
    {
        try {
            $ref = (string) ($this->option('base') ?? $config->get('standards.base_ref') ?? ChangedFiles::defaultRef($base));
            $paths = ChangedFiles::since($base, $ref);
            $report = CheckReport::build($base, $configurationFile, $paths, 'changed', new DateTimeImmutable('today'), TreeCatalog::shipped(), (int) $config->get('standards.expiring_days', 14), ChangedFiles::sha($base));
            $record = (new Recorder($base, true, $configurationFile))->runRecord($report, $ref);
            $out = (string) $this->option('out');
            file_put_contents(str_starts_with($out, '/') ? $out : $base.'/'.$out, json_encode(['v' => RunLog::VERSION, ...$record, 'source' => 'ci', 'prev' => null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
            $summary = self::summary($record);

            if (is_string($file = getenv('GITHUB_STEP_SUMMARY')) && $file !== '') {
                @file_put_contents($file, $summary."\n", FILE_APPEND);
            }

            $this->line($summary);
        } catch (\Throwable $error) {
            $this->warn('standards:first-pass --ci could not record: '.$error->getMessage());
        }

        return 0;
    }

    /**
     * Records sorted by timestamp to the microsecond; ties keep their input order (PHP's sort is stable).
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    public static function chronological(array $records): array
    {
        $at = function (array $record): string {
            try {
                return (new DateTimeImmutable((string) ($record['ts'] ?? '')))->format('U.u');
            } catch (\Throwable) {
                return '0.000000';
            }
        };

        usort($records, fn (array $a, array $b): int => (float) $at($a) <=> (float) $at($b));

        return $records;
    }

    /** @param array<string, mixed> $record */
    public static function summary(array $record): string
    {
        $files = (array) $record['files'];
        $clean = array_filter($files, fn (array $file): bool => array_sum($file['findings']) === 0);
        if (($record['gate_error'] ?? false) === true) {
            return "### First pass\n\nThe gate could not run on this commit, so no first pass was recorded.";
        }

        $lines = ['### First pass', '', sprintf('%d of %d changed files clean on the first pushed commit (`%s`), ruleset `%s`.', count($clean), count($files), substr((string) ($record['sha'] ?? ''), 0, 12), $record['ruleset'] ?? '?')];

        if (count($clean) < count($files)) {
            $lines = [...$lines, '', '| File | Findings on changed lines |', '| --- | --- |'];

            foreach ($files as $file) {
                if (array_sum($file['findings']) > 0) {
                    $lines[] = '| `'.$file['path'].'` | '.implode(', ', array_map(fn (string $rule, int $count): string => "{$rule} ×{$count}", array_keys($file['findings']), $file['findings'])).' |';
                }
            }
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $result */
    private static function recomputeText(array $result): string
    {
        $episodes = $result['episodes'];
        $lines = [sprintf('Recomputed %s: %d commits, %d commit-level episodes, %d first pass, %d corrected, %d open.', $result['range'], $result['commits'], $episodes['episodes'], $episodes['first_pass'], $episodes['corrected'], $episodes['open'])];

        foreach ($result['mismatches'] as $mismatch) {
            $lines[] = 'Mismatch '.substr($mismatch['sha'], 0, 12).' '.$mismatch['path'].': '.$mismatch['reason'].'.';
        }

        foreach ($result['chain_breaks'] as $break) {
            $lines[] = "Chain break at line {$break['line']}: {$break['reason']}.";
        }

        $lines[] = $result['mismatches'] === [] && $result['chain_breaks'] === [] ? 'The log matches history.' : 'Flagged: '.count($result['mismatches']).' mismatches, '.count($result['chain_breaks']).' chain breaks.';

        return implode("\n", $lines);
    }
}
