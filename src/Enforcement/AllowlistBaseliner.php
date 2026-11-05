<?php

declare(strict_types=1);

namespace Tey\Standards\Enforcement;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Baselines named rules: every current finding of those rules that the allowlist lacks becomes a dated, expiring entry appended to the list,
 * so a new check can fail on new findings only while the existing ones burn down by the expiry. Entries already listed are kept as they are,
 * and rules not named are never touched. The result is read back and must equal the old list plus the new entries, or nothing is written.
 */
final class AllowlistBaseliner
{
    /**
     * @param  list<array{rule: string, target: string, check: string}>  $findings  the current violations and advisories, as standards:check reports them
     * @param  list<string>  $rules
     * @return array{file: string, rules: list<string>, expires: string, added: list<array{rule: string, target: string, check: string}>, kept: int, contents: string}
     */
    public static function plan(string $base, string $relative, array $findings, array $rules, string $expires, string $reason, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expires);

        if ($date === false || $date->format('Y-m-d') !== $expires) {
            throw new InvalidArgumentException('--expires needs a real Y-m-d date, such as 2027-03-31.');
        }

        if ($expires <= $today->format('Y-m-d')) {
            throw new InvalidArgumentException("--expires={$expires} is not in the future: every baselined entry would already be due. Pick a later date.");
        }

        $rules = array_values(array_unique(array_filter($rules, fn (string $rule): bool => trim($rule) !== '')));

        if ($rules === []) {
            throw new InvalidArgumentException('Name the rules to baseline with --rule (repeatable); a baseline never covers every rule at once.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('--reason must not be empty.');
        }

        $path = $base.'/'.$relative;

        if (! is_file($path)) {
            throw new InvalidArgumentException("No allowlist at {$relative}; pass --file=<path> to the file that returns the allowlist entries.");
        }

        $source = (string) file_get_contents($path);
        $before = self::load($source, $relative);
        $key = fn (array $entry): string => ($entry['rule'] ?? '').'|'.($entry['target'] ?? '').'|'.($entry['check'] ?? '');
        $listed = array_flip(array_map($key, $before));
        $added = [];

        foreach ($findings as $finding) {
            $identity = ['rule' => (string) $finding['rule'], 'target' => (string) $finding['target'], 'check' => (string) $finding['check']];

            if (in_array($identity['rule'], $rules, true) && ! isset($listed[$key($identity)])) {
                $listed[$key($identity)] = true;
                $added[] = $identity;
            }
        }

        $kept = count(array_filter($before, fn (array $entry): bool => in_array((string) ($entry['rule'] ?? ''), $rules, true)));

        if ($added === []) {
            return ['file' => $relative, 'rules' => $rules, 'expires' => $expires, 'added' => [], 'kept' => $kept, 'contents' => $source];
        }

        $close = self::closingBracket($source, $relative);
        $indent = preg_match("/\n([ \t]+)\[/", $source, $match) === 1 ? $match[1] : '    ';
        $quote = fn (string $value): string => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
        $lines = '';

        foreach ($added as $entry) {
            $lines .= $indent.'['
                ."'rule' => ".$quote($entry['rule']).', '
                ."'target' => ".$quote($entry['target']).', '
                ."'check' => ".$quote($entry['check']).', '
                ."'reason' => ".$quote($reason).', '
                ."'date' => '".$today->format('Y-m-d')."', "
                ."'expires' => '{$expires}'],\n";
        }

        // Before the list's closing bracket, on lines of their own, after a comma if the last entry lacks one.
        $head = rtrim(substr($source, 0, $close));
        $separator = $before !== [] && ! str_ends_with($head, ',') && ! str_ends_with($head, '[') ? ',' : '';
        $contents = $head.$separator."\n".$lines.substr($source, $close);

        $expected = [...$before, ...array_map(fn (array $entry): array => [...$entry, 'reason' => $reason, 'date' => $today->format('Y-m-d'), 'expires' => $expires], $added)];

        if (self::load($contents, $relative) !== $expected) {
            throw new InvalidArgumentException("{$relative} could not be baselined safely; add the entries by hand.");
        }

        return ['file' => $relative, 'rules' => $rules, 'expires' => $expires, 'added' => $added, 'kept' => $kept, 'contents' => $contents];
    }

    /** @param  array{file: string, contents: string}  $plan */
    public static function apply(string $base, array $plan): void
    {
        file_put_contents($base.'/'.$plan['file'], $plan['contents']);
    }

    /** The offset of the `]` that closes the returned list: the last top-level bracket in the file. */
    private static function closingBracket(string $source, string $relative): int
    {
        $depth = 0;
        $offset = 0;
        $close = null;

        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '[') {
                $depth++;
            } elseif ($text === ']') {
                if ($depth === 1) {
                    $close = $offset;
                }

                $depth--;
            }

            $offset += strlen($text);
        }

        return $close ?? throw new InvalidArgumentException("{$relative} does not return a plain [...] list; add the entries by hand.");
    }

    /** @return list<array<string, mixed>> */
    private static function load(string $source, string $relative): array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'standards-baseline-');

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
