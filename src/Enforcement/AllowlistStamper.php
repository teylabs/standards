<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Dates an app's legacy allowlist in one go: every entry without an `expires`
 * gets the given date, written after its `date` line, and nothing else in the
 * file changes. An entry that already has an expiry is never touched. The
 * result is read back and must hold the same entries, each now dated.
 */
final class AllowlistStamper
{
    public const DEFAULT = 'tests/Arch/conformance-allowlist.php';

    public function __construct(private readonly string $base) {}

    /** The allowlist file, project-relative: the one tests/Arch/configuration.php requires for `allowlist:`, else the default. */
    public function locate(?string $file = null): string
    {
        if ($file !== null && $file !== '') {
            return ltrim(str_replace('\\', '/', $file), '/');
        }

        $configuration = (string) @file_get_contents($this->base.'/'.Extractor::TARGET);

        if (preg_match("/allowlist:\s*require\s+__DIR__\s*\.\s*'([^']+)'/", $configuration, $match) === 1) {
            $parts = [];

            foreach (explode('/', dirname(Extractor::TARGET).'/'.ltrim($match[1], '/')) as $part) {
                if ($part === '..') {
                    array_pop($parts);
                } elseif ($part !== '.' && $part !== '') {
                    $parts[] = $part;
                }
            }

            return implode('/', $parts);
        }

        return self::DEFAULT;
    }

    /**
     * @return array{file: string, expires: string, stamped: list<array{rule: string, target: string, check: string}>, kept: int, contents: string}
     */
    public function plan(string $expires, ?string $file = null, ?DateTimeImmutable $today = null): array
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expires);

        if ($date === false || $date->format('Y-m-d') !== $expires) {
            throw new InvalidArgumentException('--stamp-expiry needs a real Y-m-d date, such as 2027-03-31.');
        }

        if ($expires < ($today ?? new DateTimeImmutable('today'))->format('Y-m-d')) {
            throw new InvalidArgumentException("--stamp-expiry={$expires} is in the past: every stamped entry would already be expired. Pick a future date.");
        }

        $relative = $this->locate($file);
        $path = $this->base.'/'.$relative;

        if (! is_file($path)) {
            throw new InvalidArgumentException("No allowlist at {$relative}; pass --file=<path> to the file that returns the allowlist entries.");
        }

        $source = (string) file_get_contents($path);
        $before = self::load($source, $relative);
        $tokens = token_get_all($source);
        $depth = 0;
        $start = null;
        $edits = [];
        $offset = 0;
        $stamped = [];
        $kept = 0;
        $index = 0;

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '[') {
                $depth++;

                if ($depth === 2) {
                    $start = $offset;
                }
            } elseif ($text === ']') {
                if ($depth === 2 && $start !== null) {
                    $entry = $before[$index] ?? throw new InvalidArgumentException("{$relative} is not a plain list of allowlist arrays; date its entries by hand.");
                    $index++;

                    if (array_key_exists('expires', $entry)) {
                        $kept++;
                    } else {
                        $block = substr($source, $start, $offset + 1 - $start);

                        if (preg_match("/\n([ \t]*)'date'\s*=>\s*'[^']*',?[^\n]*\n/", $block, $line, PREG_OFFSET_CAPTURE) !== 1) {
                            throw new InvalidArgumentException("{$relative}: the entry for [{$entry['rule']}] {$entry['target']} has no literal 'date' => '…' line to stamp after; date it by hand.");
                        }

                        $edits[] = [$start + $line[0][1] + strlen($line[0][0]), $line[1][0]."'expires' => '{$expires}',\n"];
                        $stamped[] = ['rule' => (string) $entry['rule'], 'target' => (string) $entry['target'], 'check' => (string) $entry['check']];
                    }
                }

                $depth--;
            }

            $offset += strlen($text);
        }

        if ($index !== count($before)) {
            throw new InvalidArgumentException("{$relative} is not a plain list of allowlist arrays; date its entries by hand.");
        }

        $contents = $source;

        foreach (array_reverse($edits) as [$at, $insert]) {
            $contents = substr($contents, 0, $at).$insert.substr($contents, $at);
        }

        // Read back: the same entries, each now dated, the stamped ones with this date and the rest untouched.
        $after = self::load($contents, $relative);
        $expected = array_map(fn (array $entry): array => array_key_exists('expires', $entry) ? $entry : [...$entry, 'expires' => $expires], $before);

        // Key order may differ (the expiry is written after `date`, wherever that sits), so compare each entry's keys sorted.
        $sorted = fn (array $entries): array => array_map(function (array $entry): array {
            ksort($entry);

            return $entry;
        }, $entries);

        if ($sorted($after) !== $sorted($expected)) {
            throw new InvalidArgumentException("{$relative} could not be stamped safely; date its entries by hand.");
        }

        return ['file' => $relative, 'expires' => $expires, 'stamped' => $stamped, 'kept' => $kept, 'contents' => $contents];
    }

    /** @param  array{file: string, contents: string}  $plan */
    public function apply(array $plan): void
    {
        file_put_contents($this->base.'/'.$plan['file'], $plan['contents']);
    }

    /** @return list<array<string, mixed>> */
    private static function load(string $source, string $relative): array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'standards-allowlist-');

        try {
            file_put_contents($temporary, $source);
            $entries = (static fn (string $path): mixed => require $path)($temporary);
        } finally {
            @unlink($temporary);
        }

        if (! is_array($entries) || ! array_is_list($entries) || array_filter($entries, fn (mixed $entry): bool => ! is_array($entry)) !== []) {
            throw new InvalidArgumentException("{$relative} must return a list of allowlist entries.");
        }

        return $entries;
    }
}
