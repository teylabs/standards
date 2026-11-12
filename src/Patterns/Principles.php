<?php

declare(strict_types=1);

namespace Tey\Standards\Patterns;

use InvalidArgumentException;
use Tey\Standards\Conformance;
use Tey\Standards\Enforcement\Gate;

/**
 * Compiles resources/patterns/principles.md, the principles catalog (one table row per checkable principle), into principles.json.
 * The markdown is the source of truth. Each row gets three derived fields, read from this package's own code, never guessed:
 *
 * - tree_leaf: the decision-tree leaf the row describes. An STD-<leaf> row names it; a KIT-<Component> row maps to the one leaf
 *   that lists `@kit/ui <Component>` (none or several: null). Any other row: null.
 * - enforced_by: the standards rule that checks the row. The candidates are a CONF-<n> row's numbered rule, an STD row's leaf checks,
 *   resources/patterns/check-ids.json, a rule the row names in brackets (`[ui toast]`) and a dotted or dashed id in backticks (`vm.page-size`).
 *   Only ids in Conformance::TESTS count. The first error-level candidate wins, else the first advisory one.
 * - mode: `enforced` for an error-level rule, `advisory` for an optional, advisory or warn-only rule, `review` when no rule checks it.
 */
final class Principles
{
    public const SOURCE = 'principles.md';

    public const COMPILED = 'principles.json';

    private const COLUMNS = ['id', 'source', 'rule', 'how to check', 'applies-to'];

    /**
     * @return array{version: string, sources: array<string, string>, patterns: list<array{id: string, section: string, source: string, rule: string, check: string, applies_to: list<string>, tree_leaf: ?string, enforced_by: ?string, mode: string}>}
     */
    public static function compile(string $directory, ?TreeCatalog $tree = null): array
    {
        $markdown = is_file($directory.'/'.self::SOURCE) ? file_get_contents($directory.'/'.self::SOURCE) : false;
        if ($markdown === false) {
            throw new InvalidArgumentException(self::SOURCE.' is missing from '.$directory.'.');
        }
        $tree ??= TreeCatalog::shipped();
        $checkIds = is_file($directory.'/check-ids.json') ? (json_decode((string) file_get_contents($directory.'/check-ids.json'), true, flags: JSON_THROW_ON_ERROR)['map'] ?? []) : [];

        $leaves = [];
        $byComponent = [];
        foreach ($tree->leaves() as $leaf) {
            $leaves[$leaf->id] = $leaf;
            foreach ($leaf->kit as $kit) {
                if (str_starts_with($kit, '@kit/ui ')) {
                    $byComponent[substr($kit, 8)][$leaf->id] = true;
                }
            }
        }

        $version = null;
        $sources = [];
        $section = null;
        $patterns = [];
        $seen = [];
        foreach (preg_split('/\R/', $markdown) as $number => $line) {
            $at = self::SOURCE.' line '.($number + 1);
            if (preg_match('/\AVersion: (\S+)\z/', $line, $match) === 1) {
                $version = $match[1];
            } elseif ($section === null && preg_match('/\A- ([^:]+): (.+)\z/', $line, $match) === 1) {
                $sources[$match[1]] = trim($match[2], ' `');
            } elseif (str_starts_with($line, '## ')) {
                $section = trim((string) preg_replace('/\s*\(.*\z/', '', substr($line, 3)));
            } elseif (str_starts_with($line, '|')) {
                $cells = array_map('trim', explode('|', trim($line, " \t|")));
                if ($cells === self::COLUMNS || preg_match('/\A[-| ]+\z/', $line) === 1) {
                    continue;
                }
                if (count($cells) !== count(self::COLUMNS)) {
                    throw new InvalidArgumentException($at.' has '.count($cells).' cells, not '.count(self::COLUMNS).'.');
                }
                if ($section === null) {
                    throw new InvalidArgumentException($at.' is a row before any section heading.');
                }
                [$id, $source, $rule, $check, $appliesTo] = $cells;
                if (preg_match('/\A[A-Z]+-[A-Za-z0-9.\-]+\z/', $id) !== 1) {
                    throw new InvalidArgumentException($at.' has an invalid id "'.$id.'".');
                }
                if (isset($seen[$id])) {
                    throw new InvalidArgumentException($at.' repeats the id '.$id.' (first on line '.$seen[$id].').');
                }
                $seen[$id] = $number + 1;

                $leaf = null;
                if (str_starts_with($id, 'STD-') && isset($leaves[substr($id, 4)])) {
                    $leaf = substr($id, 4);
                } elseif (str_starts_with($id, 'KIT-') && count($byComponent[substr($id, 4)] ?? []) === 1) {
                    $leaf = array_key_first($byComponent[substr($id, 4)]);
                }

                $candidates = [];
                if (preg_match('/\ACONF-(\d+)\z/', $id, $match) === 1) {
                    $candidates[] = $match[1];
                }
                if (str_starts_with($id, 'STD-') && $leaf !== null) {
                    array_push($candidates, ...$leaves[$leaf]->checks);
                }
                array_push($candidates, ...($checkIds[$id] ?? []));
                // A rule named in brackets (`[ui toast]`), or a dotted or dashed id in backticks (`vm.page-size`); a bare backticked word such as `mail` is prose.
                $text = $source.' '.$rule.' '.$check;
                preg_match_all('/\[([a-z0-9][a-z0-9.\- ]*[a-z0-9])\]/', $text, $bracketed);
                preg_match_all('/`([a-z][a-z0-9]*(?:[.\-][a-z0-9]+)+)`/', $text, $ticked);
                array_push($candidates, ...$bracketed[1], ...$ticked[1]);
                $enforcedBy = self::pick($candidates);

                $patterns[] = [
                    'id' => $id,
                    'section' => $section,
                    'source' => $source,
                    'rule' => $rule,
                    'check' => $check,
                    'applies_to' => array_values(array_filter(array_map('trim', explode(',', $appliesTo)), fn (string $part): bool => $part !== '')),
                    'tree_leaf' => $leaf,
                    'enforced_by' => $enforcedBy,
                    'mode' => $enforcedBy === null ? 'review' : self::mode($enforcedBy),
                ];
            }
        }
        if ($version === null) {
            throw new InvalidArgumentException(self::SOURCE.' has no "Version: <date>" line.');
        }
        if ($patterns === []) {
            throw new InvalidArgumentException(self::SOURCE.' has no rows.');
        }

        return ['version' => $version, 'sources' => $sources, 'patterns' => $patterns];
    }

    public static function json(string $directory, ?TreeCatalog $tree = null): string
    {
        return json_encode(self::compile($directory, $tree), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /** `enforced` for an error-level rule; `advisory` for a rule that is optional, advisory until opted in, or warn only. */
    public static function mode(string $rule): string
    {
        if (! array_key_exists($rule, Conformance::TESTS)) {
            throw new InvalidArgumentException('No standards rule is named '.$rule.'.');
        }
        $advisory = in_array($rule, Gate::OPTIONAL, true) || in_array($rule, Gate::ADVISORY, true) || str_contains(Conformance::TESTS[$rule], '(warn only)');

        return $advisory ? 'advisory' : 'enforced';
    }

    /** @param  list<string>  $candidates */
    private static function pick(array $candidates): ?string
    {
        $rules = array_values(array_unique(array_filter($candidates, fn (string $rule): bool => array_key_exists($rule, Conformance::TESTS))));
        foreach ($rules as $rule) {
            if (self::mode($rule) === 'enforced') {
                return $rule;
            }
        }

        return $rules[0] ?? null;
    }
}
