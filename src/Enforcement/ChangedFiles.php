<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Files changed against the merge base with a ref, plus staged, unstaged and untracked files. */
final class ChangedFiles
{
    /** @return list<string> */
    public static function since(string $base, string $ref): array
    {
        $mergeBase = self::git($base, ['merge-base', 'HEAD', $ref], allowFailure: true);
        $files = [
            ...($mergeBase === null ? [] : self::lines(self::git($base, ['diff', '--name-only', '--diff-filter=d', trim($mergeBase), 'HEAD']))),
            ...self::lines(self::git($base, ['diff', '--name-only', '--diff-filter=d', 'HEAD'], allowFailure: true)),
            ...self::lines(self::git($base, ['ls-files', '--others', '--exclude-standard'])),
        ];

        if ($mergeBase === null) {
            fwrite(STDERR, "standards:check: no merge base with {$ref}; checking working-tree changes only.\n");
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /** @return list<string> */
    private static function lines(?string $output): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $output)), fn (string $line): bool => $line !== ''));
    }

    /** @param list<string> $arguments */
    private static function git(string $base, array $arguments, bool $allowFailure = false): ?string
    {
        $process = new Process(['git', ...$arguments], $base);
        $process->run();

        if (! $process->isSuccessful()) {
            if ($allowFailure) {
                return null;
            }

            throw new RuntimeException('git '.implode(' ', $arguments).' failed: '.trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    public static function sha(string $base): ?string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], $base);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }
}
