<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

/**
 * Anonymised demand for recipes that don't exist yet: one JSON line per byo
 * or gap lookup in storage/standards/gap-demand.jsonl. A record holds only the
 * tree's own answer texts, catalog keyword criteria, the unit type and a
 * timestamp: no code, names or paths.
 */
final class GapDemand
{
    public const FILE = 'storage/standards/gap-demand.jsonl';

    /** @param array{answers: list<string>, criteria: list<string>, unit_type: string, ts: string} $record */
    public static function append(string $base, array $record): void
    {
        $file = $base.'/'.self::FILE;

        if (! is_dir(dirname($file))) {
            @mkdir(dirname($file), 0777, true);
        }

        $line = json_encode(['answers' => array_values($record['answers']), 'criteria' => array_values($record['criteria']), 'unit_type' => $record['unit_type'], 'ts' => $record['ts']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        @file_put_contents($file, $line."\n", FILE_APPEND | LOCK_EX);
    }

    /** @return array{total: int, by_unit_type: array<string, int>} */
    public static function counts(string $base): array
    {
        $counts = ['total' => 0, 'by_unit_type' => []];
        $file = $base.'/'.self::FILE;

        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            $record = json_decode($line, true);

            if (is_array($record) && is_string($record['unit_type'] ?? null)) {
                $counts['total']++;
                $counts['by_unit_type'][$record['unit_type']] = ($counts['by_unit_type'][$record['unit_type']] ?? 0) + 1;
            }
        }

        ksort($counts['by_unit_type']);

        return $counts;
    }
}
