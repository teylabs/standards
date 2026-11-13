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
 *   that lists `@kit/ui <Component>` (none or several: null). A VM-* row (the tree has no ViewModel leaf) maps by VM_LEAVES: the form leaf
 *   for the shared create/edit form ViewModel, the index leaf for lazy props, the show leaf for every other one. A row listed in ROW_LEAVES
 *   maps to its leaf there (checked against the tree). Any other row: null.
 * - enforced_by: the standards rule that checks the row. The candidates are a CONF-<n> row's numbered rule, an STD row's leaf checks,
 *   resources/patterns/check-ids.json, a rule the row names in brackets (`[ui toast]`) and a dotted or dashed id in backticks (`vm.page-size`).
 *   Only ids in Conformance::TESTS count. The first error-level candidate wins, else the first advisory one.
 * - mode: `enforced` for an error-level rule, `advisory` for an optional, advisory or warn-only rule, `review` when no rule checks it.
 */
final class Principles
{
    public const SOURCE = 'principles.md';

    public const COMPILED = 'principles.json';

    /** VM-* rows: the leaf whose page each ViewModel rule is written for; any VM row not listed here maps to VM_DEFAULT_LEAF. */
    public const VM_LEAVES = ['VM-04' => 'ui-page.form', 'VM-08' => 'ui-page.index', 'VM-28' => 'ui-page.header-actions', 'VM-40' => 'ui-page.action-menu'];

    /** Rows whose rule is written for one leaf's page, by id (from FSB's pattern pages). It wins over the STD, KIT and VM rules; every leaf must exist in the tree. */
    public const ROW_LEAVES = [
        'IDX-01' => 'ui-page.index', 'IDX-02' => 'ui-page.index', 'IDX-03' => 'ui-page.index', 'IDX-04' => 'ui-page.index', 'RUN-06' => 'ui-page.index',
        'TBL-01' => 'ui-page.data-table', 'TBL-02' => 'ui-page.data-table', 'TBL-03' => 'ui-page.data-table', 'TBL-04' => 'ui-page.data-table',
        'TBL-05' => 'ui-page.data-table', 'TBL-06' => 'ui-page.data-table', 'LNK-03' => 'ui-page.data-table',
        'BTN-05' => 'ui-page.header-actions', 'BTN-08' => 'ui-page.header-actions', 'LAY-01' => 'ui-page.header-actions', 'HR-03' => 'ui-page.header-actions',
        'BTN-06' => 'ui-page.action-menu', 'BTN-07' => 'ui-page.action-menu', 'LAY-04' => 'ui-page.action-menu',
        'BTN-01' => 'ui-page.add-button', 'BTN-03' => 'ui-page.add-button',
        'FRM-01' => 'ui-page.form', 'FRM-02' => 'ui-page.form', 'FRM-03' => 'ui-page.form', 'FRM-04' => 'ui-page.form',
        'FRM-05' => 'ui-page.form', 'FRM-06' => 'ui-page.form', 'FRM-07' => 'ui-page.form', 'FRM-08' => 'ui-page.form',
        'FRM-09' => 'ui-page.form', 'FRM-10' => 'ui-page.form', 'FRM-11' => 'ui-page.form', 'FRM-12' => 'ui-page.form',
        'FRM-13' => 'ui-page.form', 'FRM-14' => 'ui-page.form', 'FRM-15' => 'ui-page.form', 'FRM-16' => 'ui-page.form',
        'FRM-17' => 'ui-page.form', 'FRM-18' => 'ui-page.form', 'FRM-19' => 'ui-page.form', 'FRM-20' => 'ui-page.form',
        'FRM-21' => 'ui-page.form', 'MDL-05' => 'ui-page.form', 'MDL-06' => 'ui-page.form', 'CTL-03' => 'ui-page.form',
        'SET-03' => 'ui-page.form', 'KIT-Field' => 'ui-page.form', 'KIT-FormCard' => 'ui-page.form', 'KIT-FormFieldset' => 'ui-page.form',
        'KIT-FormLink' => 'ui-page.form', 'KIT-LockedForm' => 'ui-page.form', 'KIT-AddressFieldset' => 'ui-page.form', 'KIT-InputCheckbox' => 'ui-page.form',
        'KIT-InputDate' => 'ui-page.form', 'KIT-InputDateRange' => 'ui-page.form', 'KIT-InputMonth' => 'ui-page.form', 'KIT-InputNumber' => 'ui-page.form',
        'KIT-InputSwitch' => 'ui-page.form', 'KIT-InputTags' => 'ui-page.form', 'KIT-InputTime' => 'ui-page.form', 'KIT-InputTimeRange' => 'ui-page.form',
        'KIT-InputYear' => 'ui-page.form', 'KIT-PasswordInput' => 'ui-page.form', 'KIT-PasswordRules' => 'ui-page.form', 'KIT-RadioCardGroup' => 'ui-page.form',
        'KIT-Select' => 'ui-page.form', 'KIT-Textarea' => 'ui-page.form',
        'KIT-FileInput' => 'ui-page.upload',
        'MCP-05' => 'mcp.read',
        'MCP-01' => 'mcp.write', 'MCP-02' => 'mcp.write', 'MCP-03' => 'mcp.write', 'MCP-04' => 'mcp.write',
        'MCP-06' => 'mcp.write', 'MCP-08' => 'mcp.write', 'MCP-09' => 'mcp.write', 'MCP-10' => 'mcp.write',
        'SAAS-06' => 'mcp.write', 'CONF-55' => 'mcp.write',
        'MCP-07' => 'mcp.queued',
        'HR-06' => 'mcp.agent-docs', 'AGT-01' => 'mcp.agent-docs', 'AGT-02' => 'mcp.agent-docs', 'CONF-35' => 'mcp.agent-docs',
    ];

    public const VM_DEFAULT_LEAF = 'ui-page.show';

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
                if (isset(self::ROW_LEAVES[$id])) {
                    $leaf = self::ROW_LEAVES[$id];
                    if (! isset($leaves[$leaf])) {
                        throw new InvalidArgumentException($at.' maps '.$id.' to '.$leaf.', which is not a leaf of the tree.');
                    }
                } elseif (str_starts_with($id, 'STD-') && isset($leaves[substr($id, 4)])) {
                    $leaf = substr($id, 4);
                } elseif (str_starts_with($id, 'KIT-') && count($byComponent[substr($id, 4)] ?? []) === 1) {
                    $leaf = array_key_first($byComponent[substr($id, 4)]);
                } elseif (str_starts_with($id, 'VM-')) {
                    $leaf = self::VM_LEAVES[$id] ?? self::VM_DEFAULT_LEAF;
                    if (! isset($leaves[$leaf])) {
                        throw new InvalidArgumentException($at.' maps '.$id.' to '.$leaf.', which is not a leaf of the tree.');
                    }
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
