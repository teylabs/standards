<?php

declare(strict_types=1);

namespace Tey\Standards\Telemetry;

use Symfony\Component\Process\Process;

/** Changed line numbers per file, from `git diff -U0`. A file git doesn't know (untracked or new) is new: every line counts as changed. */
final class Diff
{
    /**
     * Lines changed in the working tree against $from (a commit), or in $to against $from when $to is given.
     *
     * @param  list<string>  $files
     * @return array<string, array{new: bool, lines: list<int>}>
     */
    public static function lines(string $base, array $files, ?string $from, ?string $to = null): array
    {
        $result = array_fill_keys($files, ['new' => true, 'lines' => []]);

        if ($files === [] || $from === null) {
            return $result;
        }

        $known = self::git($base, ['ls-tree', '-r', '--name-only', $from, '--', ...$files]);

        foreach (array_filter(explode("\n", (string) $known)) as $file) {
            if (isset($result[$file])) {
                $result[$file]['new'] = false;
            }
        }

        $diff = self::git($base, ['diff', '-U0', '--no-color', '--no-ext-diff', '--no-renames', $from, ...($to === null ? [] : [$to]), '--', ...$files]);
        $current = null;

        foreach (explode("\n", (string) $diff) as $line) {
            if (str_starts_with($line, '+++ ')) {
                $path = substr($line, 4);
                $current = $path === '/dev/null' ? null : (string) preg_replace('#^b/#', '', $path);

                continue;
            }

            if ($current !== null && isset($result[$current]) && preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $hunk) === 1) {
                $start = (int) $hunk[1];
                $count = isset($hunk[2]) && $hunk[2] !== '' ? (int) $hunk[2] : 1;

                for ($number = $start; $number < $start + $count; $number++) {
                    $result[$current]['lines'][] = $number;
                }
            }
        }

        return $result;
    }

    public static function mergeBase(string $base, string $ref): ?string
    {
        $sha = self::git($base, ['merge-base', 'HEAD', $ref]);

        return $sha === null || trim($sha) === '' ? (self::git($base, ['rev-parse', '--verify', '--quiet', 'HEAD']) === null ? null : 'HEAD') : trim($sha);
    }

    /** @param list<string> $arguments */
    public static function git(string $base, array $arguments): ?string
    {
        $process = new Process(['git', ...$arguments], $base);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }
}
