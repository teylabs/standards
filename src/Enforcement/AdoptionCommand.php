<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Tey\Standards\Adoption\AdoptionError;
use Tey\Standards\Adoption\App;
use Tey\Standards\Adoption\Scanner;
use Throwable;

/**
 * `standards:adoption`: which package surfaces the app adopted, hand-rolled, is missing or doesn't need, and which of its own components
 * and libraries no surface covers (gaps). Exit 0 for the report; with --ci or --compliance 1 when anything blocks; 2 when it cannot give a
 * trustworthy report (fails closed).
 */
final class AdoptionCommand extends Command
{
    public $signature = 'standards:adoption {--inventory=* : An adoption surfaces file to read (repeatable; replaces the installed copy of the same package)} {--json : Write the format-1 adoption JSON to stdout} {--ci : Exit 1 on any hand-rolled or missing row or gap without a valid exemption} {--compliance : Exit 1 on any hand-rolled, missing, exempt or gap row (zero exemptions)}';

    public $description = 'Report each package surface as adopted, hand-rolled, missing, n/a or exempt, plus gaps';

    /** The pattern-catalog leaf the report points at for the fix recipes. */
    public const PATTERN = 'ui-page.kit-adoption';

    public function handle(Repository $config): int
    {
        $base = $this->laravel->basePath();
        $mode = $this->option('compliance') ? 'compliance' : ($this->option('ci') ? 'ci' : 'report');
        $file = (string) $config->get('standards.configuration', 'tests/Arch/configuration.php');

        try {
            try {
                $configuration = CheckReport::configuration($base, $file);
            } catch (Throwable $error) {
                throw new AdoptionError($error->getMessage().' '.CheckReport::missingHint($base, $file));
            }
            [$tables, $source] = $this->adoptedTables();
            if ($source !== null) {
                fwrite(STDERR, 'Note: '.$source.PHP_EOL);
            }
            $app = new App($base, $configuration);
            $report = (new Scanner($app, Scanner::inventories($base, array_values(array_filter((array) $this->option('inventory'), 'is_string'))), $configuration, new DateTimeImmutable('today'), $tables, $file))->report($mode);
        } catch (AdoptionError $error) {
            if ($this->option('json')) {
                $this->line(json_encode(['format' => Scanner::FORMAT, 'mode' => $mode, 'error' => $error->getMessage(), 'exit_code' => 2], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->line('<fg=red>standards:adoption failed closed:</> '.$error->getMessage());
            }

            return 2;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach (self::text($report) as $line) {
                $this->line($line);
            }
        }

        return $report['exit_code'];
    }

    /**
     * The tables tey/kit recorded in `kit_adoptions` (adopted in place), from the app's default database when it has that table.
     *
     * @return array{0: list<string>, 1: ?string} [tables, a note when they could not be read]
     */
    private function adoptedTables(): array
    {
        if (! $this->laravel->bound('db')) {
            return [[], null];
        }
        try {
            $connection = $this->laravel->make('db')->connection();
            if (! $connection->getSchemaBuilder()->hasTable('kit_adoptions')) {
                return [[], null];
            }

            return [array_values(array_map('strval', $connection->table('kit_adoptions')->pluck('table')->all())), null];
        } catch (Throwable $error) {
            return [[], 'kit_adoptions could not be read ('.$error->getMessage().'); a migration signal treats every created table as hand-rolled.'];
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    public static function text(array $report): array
    {
        $lines = [];
        $marks = ['adopted' => '<fg=green>adopted</>', 'hand-rolled' => '<fg=red>hand-rolled</>', 'missing' => '<fg=red>missing</>', 'n/a' => '<fg=gray>n/a</>', 'exempt' => '<fg=yellow>exempt</>'];
        $package = null;
        $group = null;
        foreach ($report['rows'] as $row) {
            if ($row['package'] !== $package) {
                $inventory = array_values(array_filter($report['inventories'], fn (array $i): bool => $i['package'] === $row['package']))[0];
                $lines[] = '';
                $lines[] = "<options=bold>{$row['package']}</>".($inventory['version'] === null ? '' : ' '.$inventory['version']).($inventory['installed'] ? '' : ' (not installed)').' — '.$inventory['source'];
                [$package, $group] = [$row['package'], null];
            }
            if ($row['group'] !== $group) {
                $lines[] = '  '.$row['group'];
                $group = $row['group'];
            }
            $evidence = array_map(fn (array $e): string => $e['file'].($e['line'] === null ? '' : ':'.$e['line']), array_slice($row['evidence'], 0, 3));
            $detail = match ($row['status']) {
                'missing' => 'expects '.Scanner::expects($row['uses']).($row['note'] === null ? '' : ' ('.$row['note'].')'),
                'exempt' => 'allowlist '.$row['exemption']['file'].($row['exemption']['line'] === null ? '' : ':'.$row['exemption']['line']).', expires '.$row['exemption']['expires'].' — '.$row['exemption']['reason'],
                default => implode(', ', $evidence).(count($row['evidence']) > 3 ? ' (+'.(count($row['evidence']) - 3).')' : ''),
            };
            $lines[] = sprintf('    %-28s %-36s %s  %s%s', $row['id'], $row['label'], $marks[$row['status']], $detail, $row['new'] ? " (new in {$row['since']})" : '');
        }

        $lines[] = '';
        $lines[] = '<options=bold>GAPS</> (app components and libraries no surface covers)';
        foreach ($report['gaps'] as $gap) {
            $lines[] = '    '.($gap['exemption'] === null ? '<fg=red>gap</>' : '<fg=yellow>exempt</>').'  '.$gap['subject'].($gap['line'] === null ? '' : ':'.$gap['line']).' — '.$gap['detail'];
        }
        if ($report['gaps'] === []) {
            $lines[] = '    none';
        }

        foreach ([...$report['exemptions']['invalid'], ...$report['exemptions']['stale']] as $entry) {
            $lines[] = "<fg=yellow>!</> allowlist {$entry['rule']} {$entry['target']} ({$entry['file']}".($entry['line'] === null ? '' : ':'.$entry['line']).'): '.$entry['problem'];
        }
        foreach ($report['warnings'] as $warning) {
            $lines[] = '<fg=yellow>Warning:</> '.$warning;
        }

        $summary = $report['summary'];
        $lines[] = '';
        $lines[] = sprintf('Summary: %d adopted, %d hand-rolled, %d missing, %d n/a, %d exempt; %d gaps (%d exempt).', $summary['adopted'], $summary['hand-rolled'], $summary['missing'], $summary['n/a'], $summary['exempt'], $summary['gaps'], $summary['exempt_gaps']);

        if ($report['mode'] !== 'report') {
            $lines[] = '';
            if ($report['blocking'] === []) {
                $lines[] = "standards:adoption --{$report['mode']} passed.";
            } else {
                $lines[] = "standards:adoption --{$report['mode']}: ".count($report['blocking']).' blocking, in order:';
                foreach ($report['blocking'] as $item) {
                    $where = $item['evidence'] === [] ? 'expects '.$item['expects'] : implode(', ', array_map(fn (array $e): string => $e['file'].($e['line'] === null ? '' : ':'.$e['line']), $item['evidence']));
                    $lines[] = '  '.$item['kind'].'  '.($item['package'] === null ? '' : $item['package'].' ').$item['subject'].'  '.$where.(isset($item['problem']) ? ' — '.$item['problem'] : '');
                }
            }
        }
        if ($report['blocking'] !== []) {
            $lines[] = 'Recipes: php artisan standards:pattern --recipe='.self::PATTERN;
        }

        return $lines;
    }
}
