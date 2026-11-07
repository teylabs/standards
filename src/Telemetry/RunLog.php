<?php

declare(strict_types=1);

namespace Tey\Standards\Telemetry;

use Throwable;

/**
 * The first-pass run log: storage/standards/runs.jsonl, one JSON record per gate run or pattern lookup. Each line carries `prev`, the sha256
 * of the raw line before it (null on the first line), so an edited line, a line deleted from the middle or a reorder shows up as a chain
 * break. Removing lines from the end leaves no trace: that is a known limit of a local log. The authoritative checks are CI and the
 * recompute, which flags committed files the log never gated. It holds paths, rule ids, line numbers, counts and content hashes, never
 * source code. Writing fails open: an error is swallowed and never fails a run.
 */
final class RunLog
{
    public const FILE = 'storage/standards/runs.jsonl';

    public const VERSION = 1;

    /** @param array<string, mixed> $record */
    public static function append(string $file, array $record): bool
    {
        try {
            if (! is_dir(dirname($file)) && (self::blocked(dirname($file)) || (! @mkdir(dirname($file), 0777, true) && ! is_dir(dirname($file))))) {
                return false;
            }

            // Git-ignored: the directory's own .gitignore names the log, since a Laravel app's storage/ ignores only its known subfolders.
            $ignore = dirname($file).'/.gitignore';
            $ignored = is_file($ignore) ? (string) file_get_contents($ignore) : '';

            $missing = array_diff([basename($file), '.gitignore'], array_map('trim', explode("\n", $ignored)));

            if ($missing !== []) {
                @file_put_contents($ignore, ($ignored === '' || str_ends_with($ignored, "\n") ? $ignored : $ignored."\n").implode("\n", $missing)."\n");
            }

            $handle = @fopen($file, 'c+');

            if ($handle === false) {
                return false;
            }

            try {
                if (! flock($handle, LOCK_EX)) {
                    return false;
                }

                $last = self::lastLine($handle);
                $line = json_encode(['v' => self::VERSION, ...$record, 'prev' => $last === null ? null : hash('sha256', $last)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                fseek($handle, 0, SEEK_END);
                $written = fwrite($handle, $line."\n");
                fflush($handle);
                flock($handle, LOCK_UN);

                return $written !== false;
            } finally {
                fclose($handle);
            }
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Every record, in file order, and the chain breaks: a line whose `prev` is not the hash of the line before it, or a line that is not a
     * JSON object.
     *
     * @return array{records: list<array<string, mixed>>, breaks: list<array{line: int, reason: string}>}
     */
    public static function read(string $file): array
    {
        $records = [];
        $breaks = [];
        $previous = null;

        if (! is_file($file)) {
            return ['records' => [], 'breaks' => []];
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
            if ($line === '') {
                continue;
            }

            $record = json_decode($line, true);

            if (! is_array($record)) {
                $breaks[] = ['line' => $index + 1, 'reason' => 'not a JSON record'];
                $previous = $line;

                continue;
            }

            $expected = $previous === null ? null : hash('sha256', $previous);

            if (($record['prev'] ?? null) !== $expected) {
                $breaks[] = ['line' => $index + 1, 'reason' => $previous === null ? 'the first line has a prev hash: earlier lines are missing' : 'prev does not match the line before it'];
            }

            $records[] = $record;
            $previous = $line;
        }

        return ['records' => $records, 'breaks' => $breaks];
    }

    /** A file stands where a directory on the path should be. */
    private static function blocked(string $directory): bool
    {
        for ($path = $directory; $path !== dirname($path); $path = dirname($path)) {
            if (file_exists($path)) {
                return ! is_dir($path);
            }
        }

        return false;
    }

    /** @param resource $handle */
    private static function lastLine($handle): ?string
    {
        $size = fstat($handle)['size'] ?? 0;

        if ($size === 0) {
            return null;
        }

        $chunk = 65536;
        $buffer = '';
        $position = $size;

        while ($position > 0) {
            $read = min($chunk, $position);
            $position -= $read;
            fseek($handle, $position);
            $buffer = fread($handle, $read).$buffer;
            $trimmed = rtrim($buffer, "\n");

            if (($break = strrpos($trimmed, "\n")) !== false) {
                return substr($trimmed, $break + 1);
            }

            if ($position === 0) {
                return $trimmed === '' ? null : $trimmed;
            }
        }

        return null;
    }
}
