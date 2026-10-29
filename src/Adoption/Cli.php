<?php

declare(strict_types=1);

namespace Tey\Standards\Adoption;

use DateTimeImmutable;
use Tey\Standards\Enforcement\CheckReport;

/**
 * `bin/standards adoption`: the standards:adoption scan without booting Laravel, for bare trees (a package's adoption fixtures in its CI).
 * Reads the app's tests/Arch/configuration.php when it has one, else the default (null) configuration: frontend under resources/js, pages
 * under any `pages/` directory there. `kit_adoptions` is not read (no database), so a migration signal treats every created table as
 * hand-rolled. Each JSON row also carries `surface`, `facts` (the facts its `applies` names, with their values) and `row`, the fields of a
 * package's adoption fixture `expected.json`, so the package can diff against it.
 */
final class Cli
{
    public const USAGE = 'Usage: standards adoption [--base=<dir>] [--inventory=<file>]... [--json] [--ci|--compliance]';

    public const CONFIGURATION = 'tests/Arch/configuration.php';

    /**
     * @param  list<string>  $arguments  after `adoption`
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public static function run(array $arguments, string $cwd): array
    {
        $base = $cwd;
        $inventories = [];
        $json = false;
        $mode = 'report';
        for ($i = 0; $i < count($arguments); $i++) {
            $argument = $arguments[$i];
            if (in_array($argument, ['--base', '--inventory'], true)) {
                if (! isset($arguments[$i + 1])) {
                    return self::usage("{$argument} needs a value.");
                }
                $argument .= '='.$arguments[++$i];
            }
            if ($argument === '--json') {
                $json = true;
            } elseif ($argument === '--ci' || $argument === '--compliance') {
                $mode = $mode === 'compliance' ? 'compliance' : substr($argument, 2);
            } elseif (str_starts_with($argument, '--base=')) {
                $base = substr($argument, 7);
            } elseif (str_starts_with($argument, '--inventory=')) {
                $inventories[] = substr($argument, 12);
            } elseif ($argument === '--help') {
                return ['exit' => 0, 'stdout' => self::USAGE.PHP_EOL, 'stderr' => ''];
            } else {
                return self::usage("Unknown option {$argument}.");
            }
        }
        $absolute = fn (string $path): bool => preg_match('~\A(?:[/\\\\]|[A-Za-z]:[/\\\\])~', $path) === 1;
        $resolved = $base === '' ? false : realpath($absolute($base) ? $base : $cwd.'/'.$base);
        if ($resolved === false || ! is_dir($resolved)) {
            return self::usage('--base must name an existing directory.');
        }
        // An explicit relative --inventory is relative to where the command runs, not to the app.
        $inventories = array_map(fn (string $path): string => $absolute($path) ? $path : $cwd.'/'.$path, $inventories);

        try {
            $configuration = null;
            if (is_file($resolved.'/'.self::CONFIGURATION)) {
                try {
                    $configuration = CheckReport::configuration($resolved, self::CONFIGURATION);
                } catch (\Throwable $error) {
                    throw new AdoptionError($error->getMessage());
                }
            }
            $loaded = Scanner::inventories($resolved, $inventories);
            $report = (new Scanner(new App($resolved, $configuration), $loaded, $configuration, new DateTimeImmutable('today'), [], $configuration === null ? null : self::CONFIGURATION))->report($mode);
        } catch (AdoptionError $error) {
            return $json
                ? ['exit' => 2, 'stdout' => json_encode(['format' => Scanner::FORMAT, 'mode' => $mode, 'error' => $error->getMessage(), 'exit_code' => 2], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, 'stderr' => '']
                : ['exit' => 2, 'stdout' => '', 'stderr' => 'standards adoption failed closed: '.$error->getMessage().PHP_EOL];
        }

        $report['rows'] = self::outcomes($report, $loaded);
        $text = $json
            ? json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL
            : implode(PHP_EOL, array_map(fn (string $line): string => (string) preg_replace('~</?[a-z=;,]*>~', '', $line), \Tey\Standards\Enforcement\AdoptionCommand::text($report))).PHP_EOL;

        return ['exit' => $report['exit_code'], 'stdout' => $text, 'stderr' => ''];
    }

    /**
     * Each row with the expected.json fields: `surface` (its id), `facts` (fact id => value for each fact its `applies` names) and `row`
     * (its status).
     *
     * @param  array<string, mixed>  $report
     * @param  list<Inventory>  $inventories
     * @return list<array<string, mixed>>
     */
    public static function outcomes(array $report, array $inventories): array
    {
        $applies = [];
        foreach ($inventories as $inventory) {
            foreach ($inventory->surfaces as $surface) {
                $applies[$inventory->composer().' '.$surface['id']] = self::named($surface['applies']);
            }
        }

        return array_map(function (array $row) use ($report, $applies): array {
            $facts = [];
            foreach ($applies[$row['package'].' '.$row['id']] ?? [] as $fact) {
                $facts[$fact] = $report['facts'][$row['package']][$fact]['value'] ?? null;
            }

            return [...$row, 'surface' => $row['id'], 'facts' => $facts, 'row' => $row['status']];
        }, $report['rows']);
    }

    /** @return list<string> the fact ids a condition names, in order, once each */
    private static function named(mixed $condition): array
    {
        if (is_string($condition)) {
            return [$condition];
        }
        if (! is_array($condition)) {
            return [];
        }
        $names = [];
        foreach ($condition as $operand) {
            foreach (is_array($operand) && array_is_list($operand) ? $operand : [$operand] as $inner) {
                $names = [...$names, ...self::named($inner)];
            }
        }

        return array_values(array_unique($names));
    }

    /** @return array{exit: int, stdout: string, stderr: string} */
    private static function usage(string $problem): array
    {
        return ['exit' => 2, 'stdout' => '', 'stderr' => 'standards adoption: '.$problem.PHP_EOL.self::USAGE.PHP_EOL];
    }
}
