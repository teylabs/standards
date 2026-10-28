<?php

declare(strict_types=1);

namespace Tey\Standards;

use DateTimeImmutable;
use InvalidArgumentException;

final class Ratchet
{
    /** @var list<array{rule: string, target: string, check: string, reason: string, date: string, expires: ?string}> */
    private readonly array $entries;

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function __construct(array $entries)
    {
        $seen = [];
        $validated = [];

        foreach ($entries as $entry) {
            $entry = [
                'rule' => self::field($entry, 'rule'),
                'target' => self::field($entry, 'target'),
                'check' => self::field($entry, 'check'),
                'reason' => self::field($entry, 'reason'),
                'date' => self::field($entry, 'date'),
                'expires' => isset($entry['expires']) ? self::field($entry, 'expires') : null,
            ];

            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $entry['date']);

            if ($date === false || $date->format('Y-m-d') !== $entry['date']) {
                throw new InvalidArgumentException('Conformance exception date must be a real Y-m-d date.');
            }

            foreach (array_filter([$entry['date'], $entry['expires']]) as $value) {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

                if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
                    throw new InvalidArgumentException('Conformance exception dates must be real Y-m-d dates.');
                }
            }

            $key = $entry['rule'].' | '.$entry['target'].' | '.$entry['check'];

            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Duplicate conformance exception: {$key}");
            }

            $seen[$key] = true;
            $validated[] = $entry;
        }

        $this->entries = $validated;
    }

    /** @return list<array{rule: string, target: string, check: string, reason: string, date: string, expires: ?string}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @param array<string, mixed> $entry */
    private static function field(array $entry, string $field): string
    {
        $value = $entry[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Conformance exception needs a non-empty [{$field}].");
        }

        return $value;
    }

    /**
     * @param  list<array{target: string, check: string}>  $violations
     * @return list<string>
     */
    public function failures(string $rule, array $violations): array
    {
        $allowed = [];
        $undated = [];

        foreach ($this->entries as $entry) {
            if ($entry['rule'] === $rule) {
                $allowed[] = $entry['target'].' | '.$entry['check'];

                // Expiry is required, as standards:check reports it (missing-expiry): an undated entry would allow its debt for good.
                if ($entry['expires'] === null) {
                    $undated[] = $entry['target'].' | '.$entry['check'];
                }
            }
        }

        $actual = array_map(fn (array $entry): string => $entry['target'].' | '.$entry['check'], $violations);

        return [
            ...array_map(fn (string $key): string => "violation: {$key}", array_values(array_diff($actual, $allowed))),
            ...array_map(fn (string $key): string => "stale exception, delete it: {$key}", array_values(array_diff($allowed, $actual))),
            ...array_map(fn (string $key): string => "exception has no expiry, give it an `expires` Y-m-d date (php artisan standards:allowlist --stamp-expiry=YYYY-MM-DD dates them all): {$key}", $undated),
        ];
    }

    /** @param list<string> $rules */
    public function assertRules(array $rules): void
    {
        foreach ($this->entries as $entry) {
            if (! in_array($entry['rule'], $rules, true)) {
                throw new InvalidArgumentException("Exception names an unregistered rule [{$entry['rule']}].");
            }
        }
    }
}
