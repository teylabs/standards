<?php

declare(strict_types=1);

namespace Tey\Standards\Telemetry;

/**
 * Rebuilds file-change episodes from run records. An episode opens at the first gate run that sees a file whose content differs from
 * its last clean state, and closes at the next run that finds it clean. First pass is the findings on changed lines at the opening run:
 * clean then is a first pass; clean later is corrected, after (runs - 1) corrections. A file that drops out of a changed or full run while
 * its episode is open, or is deleted, was reverted: abandoned.
 */
final class Episodes
{
    /**
     * @param  list<array<string, mixed>>  $records  run and lookup records in log order
     * @return list<array<string, mixed>>
     */
    public static function build(array $records): array
    {
        $episodes = [];
        $open = [];
        $clean = [];
        $lookups = array_values(array_filter($records, fn (array $record): bool => ($record['kind'] ?? null) === 'lookup'));

        foreach ($records as $record) {
            if (($record['kind'] ?? null) !== 'run' || ($record['gate_error'] ?? false) === true) {
                continue;
            }

            $source = (string) ($record['source'] ?? 'local');
            $seen = [];

            foreach ((array) ($record['files'] ?? []) as $file) {
                if (! is_array($file) || ! is_string($file['path'] ?? null)) {
                    continue;
                }

                $key = $source."\0".$file['path'];
                $seen[$key] = true;
                $findings = array_filter((array) ($file['findings'] ?? []), fn (mixed $count): bool => (int) $count > 0);

                if (isset($open[$key])) {
                    $episode = &$episodes[$open[$key]];

                    if (($file['hash'] ?? null) === null) {
                        $episode['status'] = 'abandoned';
                        unset($open[$key]);
                    } else {
                        $episode['runs']++;

                        if ($findings === []) {
                            $episode['status'] = 'corrected';
                            $episode['closed_at'] = $record['ts'] ?? null;
                            $clean[$key] = $file['hash'];
                            unset($open[$key]);
                        }
                    }

                    unset($episode);

                    continue;
                }

                if (($file['hash'] ?? null) === null || (($clean[$key] ?? null) === $file['hash'] && $findings === [])) {
                    continue;
                }

                $episodes[] = [
                    'path' => $file['path'],
                    'source' => $source,
                    'opened_at' => $record['ts'] ?? null,
                    'closed_at' => $findings === [] ? ($record['ts'] ?? null) : null,
                    'sha' => $record['sha'] ?? null,
                    'agent' => self::agent($record),
                    'ruleset' => $record['ruleset'] ?? null,
                    'standards' => $record['standards'] ?? null,
                    'patterns' => array_values((array) ($file['patterns'] ?? [])),
                    'first_pass' => array_map('intval', $findings),
                    'runs' => 1,
                    'status' => $findings === [] ? 'first-pass' : 'open',
                    'consulted' => ($sourceLookups = array_values(array_filter($lookups, fn (array $lookup): bool => ($lookup['source'] ?? 'local') === $source))) !== [] || $source === 'local' ? self::consulted($sourceLookups, $file, $record) : null,
                ];

                if ($findings === []) {
                    $clean[$key] = $file['hash'];
                } else {
                    $open[$key] = array_key_last($episodes);
                }
            }

            // A changed or full run lists every changed file: an open episode missing from it was reverted.
            if (in_array($record['scope'] ?? null, ['changed', 'full'], true)) {
                foreach ($open as $key => $index) {
                    if (str_starts_with($key, $source."\0") && ! isset($seen[$key])) {
                        $episodes[$index]['status'] = 'abandoned';
                        unset($open[$key]);
                    }
                }
            }
        }

        return $episodes;
    }

    /** @param array<string, mixed> $record */
    public static function agent(array $record): string
    {
        $who = (array) ($record['who'] ?? []);

        return (string) ($who['solo_process_id'] ?? $who['session'] ?? 'unattributed');
    }

    /**
     * Whether a lookup naming the file, or one of the file's pattern leaves, came before the file's first write. The write time is the
     * mtime the opening run saw: conservative, since a later edit only moves it forward.
     *
     * @param  list<array<string, mixed>>  $lookups
     * @param  array<string, mixed>  $file
     * @param  array<string, mixed>  $run
     */
    private static function consulted(array $lookups, array $file, array $run): bool
    {
        $written = is_int($file['mtime'] ?? null) ? $file['mtime'] : strtotime((string) ($run['ts'] ?? 'now'));
        $patterns = (array) ($file['patterns'] ?? []);

        foreach ($lookups as $lookup) {
            $at = strtotime((string) ($lookup['ts'] ?? ''));

            if ($at === false || $at > $written) {
                continue;
            }

            if (in_array($file['path'], (array) ($lookup['paths'] ?? []), true) || array_intersect($patterns, (array) ($lookup['patterns'] ?? [])) !== []) {
                return true;
            }
        }

        return false;
    }
}
