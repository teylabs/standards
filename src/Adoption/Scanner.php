<?php

declare(strict_types=1);

namespace Tey\Standards\Adoption;

use DateTimeImmutable;
use InvalidArgumentException;
use Tey\Standards\Checks\KitShell;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;
use Tey\Standards\Ratchet;
use Tey\Standards\RuleUnavailable;
use Throwable;

/**
 * Builds the `standards:adoption` report (format 1) for one app against any number of package inventories (Inventory). Each surface reads:
 *
 * - `n/a` when its `applies` condition is false for the app's facts;
 * - `hand-rolled` when any `handRolled` signal fires in an app-owned file, whether or not the package is installed (even if `uses` also match);
 * - `missing` when the package or the surface's `feature` is not installed;
 * - `hand-rolled` when, with no `uses` match, an app component, library or composer package maps to it through `replaces`;
 * - `adopted` when any `uses` matches in an app-owned file (kit-owned unedited files never count; `files` count by presence);
 * - `missing` otherwise;
 * - `exempt` when it would be hand-rolled or missing and a valid allowlist entry (rule `adoption.<composer>.<surface-id>`) covers it.
 *
 * Direction 2 lists app components and package.json libraries that map to no surface's `replaces` as gaps (Lists holds the closed lists).
 * The report never executes app code; a `{kind: rule}` signal runs that standards rule through Conformance.
 */
final class Scanner
{
    public const FORMAT = 1;

    public const STATUSES = ['adopted', 'hand-rolled', 'missing', 'n/a', 'exempt'];

    public const MODES = ['report', 'ci', 'compliance'];

    /** The import aliases that name tey/kit's UI barrel: an import from one is an import from the others. */
    public const KIT_ALIASES = ['@kit/ui', '@/components/Kit'];

    public const GAP_RULE = 'adoption.gap';

    /** The cause a blocking row carries when a migration signal fired but `kit_adoptions` could not be read. */
    public const UNREADABLE_ADOPTIONS = 'adoption records unreadable (kit_adoptions)';

    private const MAIL_VIEW = '~^resources/views/(?:.+/)?(?:mail|mails|email|emails)/~';

    private readonly Facts $facts;

    /** @var array<string, list<array<string, mixed>>> rule => findings */
    private array $ruleFindings = [];

    /** @var array<string, string> rule => why it could not run (RuleUnavailable) */
    private array $unavailable = [];

    /** @var \Closure(string): list<array<string, mixed>> */
    private readonly \Closure $runRule;

    /** @var array<string, string> */
    private array $versions;

    /**
     * @param  list<Inventory>  $inventories
     * @param  list<string>  $adoptedTables  tables recorded in tey/kit's `kit_adoptions` (adopted in place, not hand-rolled)
     * @param  ?string  $configurationFile  project-relative file the allowlist lives in, for file:line citations
     * @param  bool  $adoptionRecordsUnreadable  `kit_adoptions` exists but could not be read: a created table reads hand-rolled, with that cause
     * @param  ?\Closure(string): list<array<string, mixed>>  $runRule  runs a standards rule (default: Conformance); throws RuleUnavailable when it can't run
     */
    public function __construct(
        private readonly App $app,
        private readonly array $inventories,
        private readonly ?Configuration $config,
        private readonly DateTimeImmutable $today,
        private readonly array $adoptedTables = [],
        private readonly ?string $configurationFile = null,
        private readonly bool $adoptionRecordsUnreadable = false,
        ?\Closure $runRule = null,
    ) {
        $this->runRule = $runRule ?? function (string $rule): array {
            if (($missing = Conformance::missingDependencies()) !== null) {
                throw new RuleUnavailable($missing);
            }

            return (new Conformance($this->config))->violations($rule);
        };
        $this->facts = new Facts($app, fn (array $signal): array => $this->signal($signal)[0]);
        $this->versions = self::installedVersions($app->base);
    }

    /**
     * Inventories from every installed vendor package that ships one, plus explicit files (which replace a vendor file for the same package;
     * two explicit files for one package fail closed). Sorted by composer name.
     *
     * @param  list<string>  $paths
     * @return list<Inventory>
     */
    public static function inventories(string $base, array $paths): array
    {
        $byPackage = [];
        foreach (glob($base.'/vendor/*/*/'.Inventory::FILE) ?: [] as $file) {
            $inventory = Inventory::load($file, 'vendor');
            $byPackage[$inventory->composer()] = $inventory;
        }
        $explicit = [];
        foreach ($paths as $path) {
            // Absolute on any OS (/x, \\x, C:\\x or C:/x), else relative to the app.
            $file = preg_match('~\A(?:[/\\\\]|[A-Za-z]:[/\\\\])~', $path) === 1 ? $path : $base.'/'.$path;
            $inventory = Inventory::load($file, 'option');
            if (isset($explicit[$inventory->composer()])) {
                throw new AdoptionError("Two --inventory files describe {$inventory->composer()} ({$explicit[$inventory->composer()]} and {$path}); pass one.");
            }
            $explicit[$inventory->composer()] = $path;
            $byPackage[$inventory->composer()] = $inventory;
        }
        ksort($byPackage);

        return array_values($byPackage);
    }

    /** @return array<string, mixed> the report; `exit_code` is for $mode */
    public function report(string $mode = 'report'): array
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown mode [{$mode}].");
        }
        if ($this->inventories === []) {
            throw new AdoptionError('No adoption inventory to read: no installed package ships '.Inventory::FILE.' and no --inventory was given.');
        }

        // --inventory may only add packages the app doesn't install (a calibration scan). Replacing an installed package's file could pass
        // a gate on a hand-written inventory, so --ci and --compliance refuse it; the plain report allows it and says so.
        $overridden = [];
        foreach ($this->inventories as $inventory) {
            if ($inventory->origin === 'option' && self::installedIn($this->app->base, $inventory->composer())) {
                $vendor = 'vendor/'.$inventory->composer().'/'.Inventory::FILE;
                $overridden[] = ['package' => $inventory->composer(), 'by' => $this->relative($inventory->source), 'vendor' => is_file($this->app->base.'/'.$vendor) ? $vendor : null];
            }
        }
        if ($overridden !== [] && $mode !== 'report') {
            throw new AdoptionError(implode(' ', array_map(fn (array $o): string => "--inventory {$o['by']} describes {$o['package']}, which the app installs; --{$mode} reads only the installed package's own inventory (--inventory may only add packages that aren't installed).", $overridden)));
        }

        $exemptions = $this->exemptions();
        $candidates = $this->candidates();
        $rows = [];
        foreach ($this->inventories as $inventory) {
            foreach ($inventory->surfaces as $index => $surface) {
                $rows[] = $this->row($inventory, $surface, $index, $candidates['mapped'], $exemptions);
            }
        }
        if ($mode !== 'report') {
            foreach ($rows as $row) {
                foreach ($row['skipped'] as $skip) {
                    throw new AdoptionError("--{$mode} fails closed: {$row['package']} {$row['id']} signal {$skip['signal']} (rule [{$skip['rule']}]) could not run: {$skip['reason']}");
                }
            }
        }
        usort($rows, fn (array $a, array $b): int => [$a['package'], array_search($a['group'], Inventory::GROUPS, true), $a['order']] <=> [$b['package'], array_search($b['group'], Inventory::GROUPS, true), $b['order']]);

        $gaps = [];
        foreach ($candidates['gaps'] as $gap) {
            $entry = $exemptions['entries'][self::GAP_RULE][$gap['subject']] ?? null;
            if ($entry !== null) {
                $exemptions['used'][$entry['key']] = true;
            }
            $gaps[] = [...$gap, 'exemption' => $entry === null || $entry['problem'] !== null ? null : self::cite($entry)];
        }

        $unused = [];
        foreach ($exemptions['all'] as $entry) {
            if (! isset($exemptions['used'][$entry['key']]) && $entry['problem'] === null) {
                $unused[] = [...self::cite($entry), 'problem' => 'stale: no hand-rolled, missing or gap row needs it; delete the entry'];
            }
        }
        $invalid = array_values(array_filter(array_map(fn (array $entry): ?array => $entry['problem'] === null ? null : [...self::cite($entry), 'problem' => $entry['problem']], $exemptions['all'])));

        $warnings = array_map(fn (array $o): string => ($o['vendor'] === null ? "inventory for {$o['package']} (installed)" : "vendor inventory for {$o['package']}").' overridden by '.$o['by'].'; --ci and --compliance refuse this.', $overridden);
        $blocking = ['ci' => [], 'compliance' => []];
        foreach ($rows as $row) {
            $subject = ['package' => $row['package'], 'subject' => $row['id']];
            if ($row['status'] === 'exempt') {
                $blocking['compliance'][] = ['kind' => 'exempt', ...$subject, 'evidence' => [self::where($row['exemption'])], 'expects' => null, 'cause' => $row['cause']];

                continue;
            }
            if (! in_array($row['status'], ['hand-rolled', 'missing'], true)) {
                continue;
            }
            $item = ['kind' => $row['status'], ...$subject, 'evidence' => $row['status'] === 'missing' ? [] : array_map(self::where(...), $row['evidence']), 'expects' => $row['status'] === 'missing' ? self::expects($row['uses']) : null, 'cause' => $row['cause']];
            $blocking['compliance'][] = $item;
            if ($row['status'] === 'missing' && $row['new'] && $this->stillNew($row)) {
                $warnings[] = "{$row['package']} {$row['id']} is missing; it is new in {$row['since']}, so --ci only warns for that release (--compliance blocks it).";
            } else {
                $blocking['ci'][] = $item;
            }
        }
        foreach ($gaps as $gap) {
            $item = ['kind' => $gap['exemption'] === null ? 'gap' : 'exempt', 'package' => null, 'subject' => $gap['subject'], 'evidence' => $gap['exemption'] === null ? array_map(self::where(...), $gap['files']) : [self::where($gap['exemption'])], 'expects' => null, 'cause' => null];
            $blocking['compliance'][] = $item;
            if ($gap['exemption'] === null) {
                $blocking['ci'][] = $item;
            }
        }
        foreach ([...$invalid, ...$unused] as $entry) {
            $item = ['kind' => 'exempt', 'package' => null, 'subject' => $entry['rule'].' '.$entry['target'], 'evidence' => [self::where($entry)], 'expects' => null, 'cause' => null, 'problem' => $entry['problem']];
            $blocking['ci'][] = $item;
            $blocking['compliance'][] = $item;
        }

        $summary = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $row) {
            $summary[$row['status']]++;
        }
        $summary['gaps'] = count(array_filter($gaps, fn (array $gap): bool => $gap['exemption'] === null));
        $summary['exempt_gaps'] = count($gaps) - $summary['gaps'];

        // Per package: two inventories may name the same fact id and compute it differently.
        $facts = [];
        foreach ($this->inventories as $inventory) {
            foreach ($inventory->facts as $id => $fact) {
                $facts[$inventory->composer()][$id] = [...$this->facts->compute($fact['computed']), 'computed' => $fact['computed']];
            }
        }

        // A route include standards could not resolve is a blind spot: recorded, never guessed at.
        array_push($warnings, ...$this->facts->blindSpots());

        $active = $mode === 'report' ? $blocking['compliance'] : $blocking[$mode];

        return [
            'format' => self::FORMAT,
            'app' => self::appName($this->app),
            'generated_at' => $this->today->format('Y-m-d'),
            'mode' => $mode,
            'lists' => Lists::VERSION,
            'overridden' => $overridden,
            'inventories' => array_map(fn (Inventory $inventory): array => [
                'package' => $inventory->composer(),
                'npm' => $inventory->package['npm'] ?? null,
                'alias' => $inventory->package['alias'] ?? null,
                'origin' => $inventory->origin,
                'source' => $this->relative($inventory->source),
                'version' => $this->versions[$inventory->composer()] ?? null,
                'installed' => self::installedIn($this->app->base, $inventory->composer()),
                'surfaces' => count($inventory->surfaces),
            ], $this->inventories),
            'facts' => $facts,
            'adopted_tables' => array_values($this->adoptedTables),
            'rows' => array_map(function (array $row): array {
                unset($row['order']);

                return $row;
            }, $rows),
            'gaps' => $gaps,
            'exemptions' => ['invalid' => $invalid, 'stale' => $unused],
            'warnings' => $warnings,
            'summary' => $summary,
            'blocking' => $active,
            'exit_code' => $mode === 'report' || $active === [] ? 0 : 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $surface
     * @param  array<string, list<array<string, mixed>>>  $mapped  "<composer> <surface id>" => candidates
     * @param  array<string, mixed>  $exemptions
     * @return array<string, mixed>
     */
    private function row(Inventory $inventory, array $surface, int $index, array $mapped, array &$exemptions): array
    {
        $package = $inventory->composer();
        $applies = $this->facts->holds($surface['applies'], $inventory->facts);
        $installed = self::installedIn($this->app->base, $package) && $this->featureInstalled($package, $surface['feature']);
        $signals = [];
        $uses = [];
        $note = null;
        $migrationFired = false;
        $skipped = [];

        if ($applies) {
            foreach ($surface['handRolled'] as $signalIndex => $signal) {
                [$fired, $adoptedBy] = $this->signal($signal);
                if ($signal['kind'] === 'rule' && isset($this->unavailable[$signal['rule']])) {
                    $skipped[] = ['signal' => $signalIndex, 'kind' => 'rule', 'rule' => $signal['rule'], 'reason' => $this->unavailable[$signal['rule']]];
                }
                array_push($signals, ...$fired);
                array_push($uses, ...$adoptedBy);
                $migrationFired = $migrationFired || ($signal['kind'] === 'migration' && $fired !== []);
            }
            array_push($uses, ...$this->uses($inventory, $surface['uses']));
        }
        $replaced = $mapped[$package.' '.$surface['id']] ?? [];

        $status = match (true) {
            ! $applies => 'n/a',
            // A hand-rolled signal is evidence on its own: the app built the thing whether or not the package (or feature) is installed.
            $signals !== [] => 'hand-rolled',
            ! $installed => 'missing',
            $uses !== [] => 'adopted',
            $replaced !== [] => 'hand-rolled',
            default => 'missing',
        };
        if ($applies && ! $installed) {
            $note = self::installedIn($this->app->base, $package) ? "feature {$surface['feature']} is not installed" : "{$package} is not installed";
        }
        $evidence = match ($status) {
            'hand-rolled' => $signals !== [] ? $signals : $replaced,
            'adopted' => $uses,
            default => [],
        };

        $exemption = null;
        $entry = $exemptions['entries']['adoption.'.$package.'.'.$surface['id']][''] ?? null;
        if ($entry !== null && in_array($status, ['hand-rolled', 'missing'], true)) {
            $exemptions['used'][$entry['key']] = true;
            if ($entry['problem'] === null) {
                $exemption = self::cite($entry);
            }
        }

        return [
            'package' => $package,
            'id' => $surface['id'],
            'layer' => $surface['layer'],
            'group' => $surface['group'],
            'label' => $surface['label'],
            'feature' => $surface['feature'],
            'installed' => $installed,
            'applies' => $applies,
            'status' => $exemption !== null ? 'exempt' : $status,
            'underlying' => $status,
            'new' => $surface['status'] === 'new',
            'since' => $surface['since'] ?? null,
            'note' => $note,
            // A created table reads hand-rolled because the adoption records could not be read, not because they say so.
            'cause' => $status === 'hand-rolled' && $migrationFired && $this->adoptionRecordsUnreadable ? self::UNREADABLE_ADOPTIONS : null,
            'evidence' => $evidence,
            // Both sides, whatever the status, so precedence is visible: hand-rolled signals that fired, and the `uses` that matched.
            'signals' => $signals,
            'matches' => $uses,
            'replaced' => $replaced,
            'uses' => $surface['uses'],
            'docs' => $surface['docs'],
            'exemption' => $exemption,
            // `{kind: rule}` signals that could not run (RuleUnavailable); the row's status comes from its other signals.
            'skipped' => $skipped,
            'order' => $index,
        ];
    }

    /**
     * @param  array<string, mixed>  $signal
     * @return array{0: list<array{file: string, line: ?int, detail: string}>, 1: list<array{file: string, line: ?int, detail: string}>} [hand-rolled evidence, adopted evidence]
     */
    private function signal(array $signal): array
    {
        $scope = $signal['scope'] ?? 'any';
        $inScope = fn (string $path): bool => $scope === 'any' || App::scope($path) === $scope;
        $fired = [];
        $adopted = [];

        switch ($signal['kind']) {
            case 'rule':
                foreach ($this->rule($signal['rule']) ?? [] as $finding) {
                    $file = (string) preg_replace('~[ :].*$~s', '', (string) $finding['target']);
                    if ($inScope($file)) {
                        $fired[] = self::evidence($file, isset($finding['line']) ? (int) $finding['line'] : null, "[{$signal['rule']}] {$finding['check']}");
                    }
                }
                break;
            case 'vue-root':
                $tags = array_map('strtolower', $signal['tags']);
                foreach ($this->owned($this->app->vue()) as $path) {
                    $source = $this->app->source($path);
                    $root = Code::root($source);
                    if ($root === null || ! in_array(strtolower($root['tag']), $tags, true) || ! $inScope($path)) {
                        continue;
                    }
                    $requires = $signal['requires'] ?? null;
                    $hits = [];
                    if ($requires !== null) {
                        foreach ($requires['routes'] ?? [] as $route) {
                            if (preg_match('~[\'"`](?:[\w./:-]*/)?'.preg_quote($route, '~').'[\'"`]~', $source) === 1) {
                                $hits[] = 'route '.$route;
                            }
                        }
                        foreach ($requires['components'] ?? [] as $glob) {
                            foreach (Code::tags($source) as $tag) {
                                // A kebab-case tag (<primary-button>) is the same component as its PascalCase form (<PrimaryButton>).
                                if (App::glob($glob, $tag['tag']) || App::glob($glob, Code::pascal($tag['tag']))) {
                                    $hits[] = '<'.$tag['tag'].'>';
                                    break;
                                }
                            }
                        }
                        if (count($hits) < ($requires['min'] ?? 1)) {
                            continue;
                        }
                    }
                    $fired[] = self::evidence($path, App::line($source, $root['offset']), 'root <'.$root['tag'].'>'.($hits === [] ? '' : ' with '.implode(', ', $hits)));
                }
                break;
            case 'file-name':
                $globMatches = fn (string $glob, string $path): bool => str_contains($glob, '/') ? App::pathGlob($glob, $path) : App::glob($glob, basename($path));
                foreach ($this->owned([...$this->app->frontend(), ...$this->app->views()]) as $path) {
                    // `except` removes a file that an include glob matched, with the same glob semantics.
                    if (! $inScope($path) || array_filter($signal['except'] ?? [], fn (string $glob): bool => $globMatches($glob, $path)) !== []) {
                        continue;
                    }
                    foreach ($signal['globs'] as $glob) {
                        if ($globMatches($glob, $path)) {
                            $fired[] = self::evidence($path, null, 'file name matches '.$glob);
                            break;
                        }
                    }
                }
                break;
            case 'import':
                foreach ($this->owned($this->app->frontend()) as $path) {
                    $source = $this->app->source($path);
                    foreach (Code::imports($source) as $import) {
                        foreach ($signal['specifiers'] as $specifier) {
                            if ($inScope($path) && self::importMatches($specifier, $import)) {
                                $fired[] = self::evidence($path, App::line($source, $import['offset']), 'imports '.$specifier);
                                break 2;
                            }
                        }
                    }
                }
                break;
            case 'template-tag':
                foreach ($this->owned($this->app->vue()) as $path) {
                    if (! $this->app->page($path) || ! $inScope($path)) {
                        continue;
                    }
                    $source = $this->app->source($path);
                    $matching = array_values(array_filter(Code::tags($source), function (array $tag) use ($signal, $source): bool {
                        // CSRF and method-spoofing fields are not hand-rolled inputs: <input type="hidden"> never counts (whatever the signal's where/except say).
                        if (strtolower($tag['tag']) === 'input' && Code::hiddenInput($source, $tag['offset'])) {
                            return false;
                        }
                        foreach ($signal['tags'] as $name) {
                            if (Code::tagIs($tag['tag'], $name)) {
                                // `except: {type: [hidden]}` repeats the built-in drop above harmlessly: a filter, so nothing counts twice.
                                return Code::attributesPass(Code::staticAttributes($source, $tag['offset']), $signal['where'] ?? [], $signal['except'] ?? []);
                            }
                        }

                        return false;
                    }));
                    if (count($matching) >= ($signal['min'] ?? 1)) {
                        $fired[] = self::evidence($path, App::line($source, $matching[0]['offset']), count($matching).' × <'.implode('|', $signal['tags']).'>'.self::filters($signal).' in a page');
                    }
                }
                break;
            case 'mail-view':
                foreach ($this->owned($this->app->views()) as $path) {
                    $source = $this->app->source($path);
                    if (preg_match(self::MAIL_VIEW, $path) !== 1 && preg_match('~<x-mail::|@component\(\s*[\'"]mail::~', $source) !== 1) {
                        continue;
                    }
                    $uses = array_filter($signal['without'], fn (string $layout): bool => str_contains($source, $layout));
                    if ($uses === []) {
                        $fired[] = self::evidence($path, null, $signal['without'] === [] ? 'an app mail view' : 'a mail view without '.implode(', ', $signal['without']));
                    }
                }
                break;
            case 'php-class':
                foreach ($this->owned($this->app->php()) as $path) {
                    if (preg_match('~^(?:routes|database/migrations|config)/~', $path) === 1) {
                        continue;
                    }
                    $source = $this->app->source($path);
                    foreach (Code::classes($source) as $class) {
                        if (self::classMatches($signal, $class)) {
                            $fired[] = self::evidence($path, App::line($source, $class['offset']), 'class '.$class['name'].($class['extends'] === null ? '' : ' extends '.$class['extends']));
                        }
                    }
                }
                break;
            case 'class-reference':
                foreach ($this->owned($this->app->php()) as $path) {
                    // `paths` narrows the app PHP read, with file-name's glob rules: a glob with `/` matches the path case-insensitively.
                    $inPaths = ! isset($signal['paths']) || array_filter($signal['paths'], fn (string $glob): bool => str_contains($glob, '/') ? App::pathGlob($glob, $path) : App::glob($glob, basename($path))) !== [];
                    if (! $inScope($path) || ! $inPaths) {
                        continue;
                    }
                    $source = $this->app->source($path);
                    $references = [];
                    $imports = [];
                    foreach ($signal['classes'] as $class) {
                        $found = Code::classReferences($source, $class);
                        [$references, $imports] = [[...$references, ...$found['references']], [...$imports, ...$found['imports']]];
                    }
                    // A `use` import counts as one reference when nothing else in the file names the class (as uses.classes reads it); otherwise
                    // only the uses count, so `use X; new X;` is one reference, not two.
                    $offsets = $references !== [] ? $references : array_slice($imports, 0, 1);
                    if ($offsets !== [] && count($offsets) >= ($signal['min'] ?? 1)) {
                        $fired[] = self::evidence($path, App::line($source, min($offsets)), ($references === [] ? 'imports ' : count($offsets).' × reference to ').implode('|', array_map(fn (string $class): string => ltrim($class, '\\'), $signal['classes'])));
                    }
                }
                break;
            case 'composer':
                foreach ($signal['packages'] as $package) {
                    if (array_key_exists($package, $this->app->composerPackages())) {
                        $fired[] = self::evidence('composer.json', null, 'requires '.$package);
                    }
                }
                break;
            case 'migration':
                foreach ($this->owned($this->app->migrations()) as $path) {
                    $source = $this->app->source($path);
                    foreach (Code::createdTables($source) as $created) {
                        if (! in_array($created['table'], $signal['tables'], true)) {
                            continue;
                        }
                        $evidence = self::evidence($path, App::line($source, $created['offset']), 'creates table '.$created['table']);
                        if (in_array($created['table'], $this->adoptedTables, true)) {
                            $adopted[] = ['detail' => 'creates table '.$created['table'].', recorded in kit_adoptions (adopted in place)'] + $evidence;
                            $adopted[array_key_last($adopted)] = self::evidence($evidence['file'], $evidence['line'], $adopted[array_key_last($adopted)]['detail']);
                        } else {
                            $fired[] = $evidence;
                        }
                    }
                }
                break;
        }

        return [$fired, $adopted];
    }

    /**
     * @param  array<string, list<string>>  $uses
     * @return list<array{file: string, line: ?int, detail: string}>
     */
    private function uses(Inventory $inventory, array $uses): array
    {
        $evidence = [];
        foreach ($uses['imports'] ?? [] as $wanted) {
            $colon = (int) strrpos($wanted, ':');
            [$alias, $name] = [substr($wanted, 0, $colon), substr($wanted, $colon + 1)];
            foreach ($this->owned($this->app->frontend()) as $path) {
                $source = $this->app->source($path);
                foreach (Code::imports($source) as $import) {
                    if (self::importsExport($alias, $name, $import)) {
                        $evidence[] = self::evidence($path, App::line($source, $import['offset']), 'imports '.$name.' from '.$import['module']);
                        break;
                    }
                }
            }
        }
        foreach ($uses['components'] ?? [] as $component) {
            foreach ($this->owned($this->app->vue()) as $path) {
                $source = $this->app->source($path);
                foreach (Code::tags($source) as $tag) {
                    if (Code::tagIs($tag['tag'], $component)) {
                        $evidence[] = self::evidence($path, App::line($source, $tag['offset']), 'renders <'.$tag['tag'].'>');
                        break;
                    }
                }
            }
        }
        foreach ($uses['files'] ?? [] as $file) {
            if ($this->app->exists($file)) {
                $evidence[] = self::evidence($file, null, 'feature file present');
            }
        }
        foreach ($uses['classes'] ?? [] as $class) {
            foreach ($this->owned($this->app->php()) as $path) {
                $source = $this->app->source($path);
                $found = Code::classReferences($source, $class);
                // A `use` import alone counts, as the class-reference signal's does; the first reference or import is the evidence line.
                $offsets = [...$found['references'], ...$found['imports']];
                if ($offsets !== []) {
                    $evidence[] = self::evidence($path, App::line($source, min($offsets)), 'references '.ltrim($class, '\\'));
                }
            }
        }
        foreach ($uses['config'] ?? [] as $key) {
            $segments = explode('.', $key);
            $file = 'config/'.array_shift($segments).'.php';
            if (! $this->app->exists($file) || $this->app->kitOwned($file)) {
                continue;
            }
            $source = $this->app->source($file);
            $code = Code::withoutComments($source);
            $at = 0;
            foreach ($segments as $segment) {
                if (preg_match('~([\'"])'.preg_quote($segment, '~').'\1\s*=>~', $code, $match, PREG_OFFSET_CAPTURE, $at) !== 1) {
                    continue 2;
                }
                $at = $match[0][1] + 1;
            }
            $evidence[] = self::evidence($file, App::line($source, $at), 'sets '.$key);
        }

        return $evidence;
    }

    /**
     * Direction 2. App components outside pages/ that import a third-party UI library or carry a generic name, package.json UI/JS
     * libraries, and composer packages a surface replaces: each maps to every surface whose `replaces` names it, or (except composer
     * packages) is a gap.
     *
     * @return array{mapped: array<string, list<array{file: string, line: ?int, detail: string}>>, gaps: list<array{subject: string, kind: string, file: string, line: ?int, detail: string, files: list<array{file: string, line: ?int, detail: string}>}>}
     */
    private function candidates(): array
    {
        $own = array_values(array_filter([$this->app->npmName(), ...array_map(fn (Inventory $inventory): ?string => $inventory->package['npm'] ?? null, $this->inventories)]));
        $local = ['.', '/', '#', '@/', '~/', ...($this->config?->frontendAliases ?? []), ...self::KIT_ALIASES, ...array_values(array_filter(array_map(fn (Inventory $inventory): ?string => $inventory->package['alias'] ?? null, $this->inventories)))];
        $thirdParty = function (string $module) use ($own, $local): ?string {
            foreach ($local as $prefix) {
                if ($module === rtrim($prefix, '/') || str_starts_with($module, $prefix)) {
                    return null;
                }
            }
            $parts = explode('/', $module);
            $package = str_starts_with($module, '@') ? implode('/', array_slice($parts, 0, 2)) : $parts[0];

            return in_array($package, $own, true) || Lists::runtime($package) || Lists::nonUi($package) ? null : $package;
        };

        $mapped = [];
        $gaps = [];
        foreach ($this->owned($this->app->vue()) as $path) {
            if ($this->app->page($path)) {
                continue;
            }
            $source = $this->app->source($path);
            $name = basename($path, '.vue');
            $libraries = [];
            foreach (Code::imports($source) as $import) {
                if (($package = $thirdParty($import['module'])) !== null) {
                    $libraries[] = [$package, $import];
                }
            }
            if ($libraries === [] && ! Lists::generic($name)) {
                continue;
            }
            $line = $libraries === [] ? null : App::line($source, $libraries[0][1]['offset']);
            $detail = $libraries === [] ? 'generic component name '.$name : 'imports '.implode(', ', array_unique(array_column($libraries, 0)));
            $targets = $this->replacing(function (array $replaces) use ($name, $libraries): bool {
                foreach ($replaces['components'] ?? [] as $glob) {
                    if (App::glob($glob, $name)) {
                        return true;
                    }
                }
                foreach ($replaces['npm'] ?? [] as $entry) {
                    [$package, $export] = array_pad(explode('#', $entry, 2), 2, null);
                    foreach ($libraries as [$library, $import]) {
                        if ($library === $package && ($export === null || in_array($export, $import['named'], true) || $import['default'] === $export)) {
                            return true;
                        }
                    }
                }

                return false;
            });
            $evidence = self::evidence($path, $line, 'app component: '.$detail);
            foreach ($targets as $key) {
                $mapped[$key][] = $evidence;
            }
            // One gap per component name, case-insensitively (BreadCrumbs.vue and Breadcrumbs.vue are one): the first-seen path is the
            // subject, and `files` lists every file.
            $key = 'component:'.strtolower($name);
            if ($targets === [] && isset($gaps[$key])) {
                $gaps[$key]['files'][] = $evidence;
            } elseif ($targets === []) {
                $gaps[$key] = ['subject' => $path, 'kind' => 'component', ...$evidence, 'files' => [$evidence]];
            }
        }

        $npm = array_keys($this->app->npmPackages());
        sort($npm);
        foreach ($npm as $package) {
            if (in_array($package, $own, true) || Lists::runtime($package) || Lists::tooling($package) || Lists::nonUi($package) || $thirdParty($package) === null) {
                continue;
            }
            $targets = $this->replacing(fn (array $replaces): bool => in_array($package, array_map(fn (string $entry): string => explode('#', $entry, 2)[0], $replaces['npm'] ?? []), true));
            $evidence = self::evidence('package.json', null, 'depends on '.$package);
            foreach ($targets as $key) {
                $mapped[$key][] = $evidence;
            }
            if ($targets === []) {
                $gaps[] = ['subject' => 'npm:'.$package, 'kind' => 'library', ...$evidence, 'files' => [$evidence]];
            }
        }

        foreach (array_keys($this->app->composerPackages()) as $package) {
            foreach ($this->replacing(fn (array $replaces): bool => in_array($package, $replaces['composer'] ?? [], true)) as $key) {
                $mapped[$key][] = self::evidence('composer.json', null, 'requires '.$package);
            }
        }

        return ['mapped' => $mapped, 'gaps' => array_values($gaps)];
    }

    /** @return list<string> "<composer> <surface id>" for every surface whose `replaces` passes the test */
    private function replacing(callable $test): array
    {
        $keys = [];
        foreach ($this->inventories as $inventory) {
            foreach ($inventory->surfaces as $surface) {
                if (isset($surface['replaces']) && $test($surface['replaces'])) {
                    $keys[] = $inventory->composer().' '.$surface['id'];
                }
            }
        }

        return $keys;
    }

    /**
     * The `adoption.*` allowlist entries, validated as every allowlist entry is (Ratchet: rule, target, check, reason, date). An entry also
     * needs an `expires` date that has not passed, or it exempts nothing and is itself reported.
     *
     * @return array{all: list<array<string, mixed>>, entries: array<string, array<string, array<string, mixed>>>, used: array<string, bool>}
     */
    private function exemptions(): array
    {
        $raw = array_values(array_filter($this->config?->allowlist ?? [], fn (array $entry): bool => is_string($entry['rule'] ?? null) && str_starts_with($entry['rule'], 'adoption.')));
        try {
            $entries = (new Ratchet($raw))->entries();
        } catch (InvalidArgumentException $error) {
            throw new AdoptionError('An adoption allowlist entry is invalid: '.$error->getMessage());
        }
        $source = $this->configurationFile !== null && $this->app->exists($this->configurationFile) ? $this->app->source($this->configurationFile) : '';
        $today = $this->today->format('Y-m-d');
        $all = [];
        $byRule = [];
        foreach ($entries as $index => $entry) {
            $gap = $entry['rule'] === self::GAP_RULE;
            $needle = $gap ? $entry['target'] : $entry['rule'];
            $offset = $source === '' ? false : strpos($source, $needle);
            $entry += [
                'key' => (string) $index,
                'file' => $this->configurationFile ?? 'allowlist',
                'line' => $offset === false ? null : App::line($source, $offset),
                'problem' => match (true) {
                    $entry['expires'] === null => 'no expiry: give it an `expires` Y-m-d date',
                    $entry['expires'] < $today => 'expired '.$entry['expires'].': fix the row, or extend `expires` with a new reason',
                    default => null,
                },
            ];
            $all[] = $entry;
            $byRule[$entry['rule']][$gap ? $entry['target'] : ''] = $entry;
        }

        return ['all' => $all, 'entries' => $byRule, 'used' => []];
    }

    /** @return ?list<array<string, mixed>> null when the rule can't run (RuleUnavailable) */
    private function rule(string $rule): ?array
    {
        if (isset($this->unavailable[$rule])) {
            return null;
        }
        if (! isset($this->ruleFindings[$rule])) {
            if ($this->config === null) {
                throw new AdoptionError("A handRolled signal reuses rule [{$rule}], which needs the app's standards configuration (tests/Arch/configuration.php).");
            }
            try {
                $findings = ($this->runRule)($rule);
            } catch (RuleUnavailable $error) {
                // Can't run here (a dependency is not loadable): the signal is skipped, not evidence. --ci and --compliance fail closed on it.
                $this->unavailable[$rule] = $error->getMessage();

                return null;
            } catch (Throwable $error) {
                throw new AdoptionError("Rule [{$rule}] could not run for a handRolled signal: {$error->getMessage()}");
            }
            $this->ruleFindings[$rule] = array_values(array_filter($findings, fn (array $finding): bool => ($finding['advisory'] ?? false) !== true));
        }

        return $this->ruleFindings[$rule];
    }

    /**
     * @param  list<string>  $paths
     * @return list<string> the app-owned ones (not kit-owned)
     */
    private function owned(array $paths): array
    {
        return array_values(array_filter($paths, fn (string $path): bool => ! $this->app->kitOwned($path)));
    }

    /** Whether the app installs the package: its vendor directory exists, or composer.json requires it. */
    public static function installedIn(string $base, string $package): bool
    {
        if (is_dir($base.'/vendor/'.$package)) {
            return true;
        }
        $composer = is_file($base.'/composer.json') ? json_decode((string) file_get_contents($base.'/composer.json'), true) : null;

        return is_array($composer) && (isset($composer['require'][$package]) || isset($composer['require-dev'][$package]));
    }

    /**
     * `core` ships with the package. tey/kit's `shell` is installed exactly when ui.kit-shell reads it adopted (KitShell::adopted: `shell` or
     * `session-ui`, which carries it). Another feature is installed when `config/<package name>.php` sets `features` to a literal list naming it,
     * or, with no such list, when an installed tey/mod lineage record names `<composer>:<feature>`.
     */
    private function featureInstalled(string $package, string $feature): bool
    {
        if ($feature === 'core') {
            return true;
        }
        // kit installs shell with session-ui: ui.kit-shell's own reading (features list, else lineage records) decides, so the two agree.
        if ($package === 'tey/kit' && $feature === 'shell') {
            return KitShell::adopted($this->app->base);
        }
        $config = 'config/'.explode('/', $package)[1].'.php';
        if ($this->app->exists($config)) {
            $code = Code::withoutComments($this->app->source($config));
            if (preg_match("~(['\"])features\\1\\s*=>\\s*\\[([^\\]]*)\\]~", $code, $list) === 1) {
                preg_match_all("~(['\"])([a-z0-9-]+)\\1~", $list[2], $ids);

                return in_array($feature, $ids[2], true);
            }
        }
        $records = glob($this->app->base.'/.mod-maintenance/installed/*.json') ?: [];
        if (is_file($this->app->base.'/.mod-maintenance/installed.json')) {
            $records[] = $this->app->base.'/.mod-maintenance/installed.json';
        }
        foreach ($records as $record) {
            $data = is_link($record) || ! is_readable($record) ? null : json_decode((string) file_get_contents($record), true);
            if (is_array($data) && ($data['format'] ?? null) === 1 && ($data['owner'] ?? null) === 'tey/mod:installed' && ($data['artifact']['name'] ?? null) === $package.':'.$feature) {
                return true;
            }
        }

        return false;
    }

    /** A new surface stays a --ci warning while the installed package is the release that added it (or its version is unknown). */
    private function stillNew(array $row): bool
    {
        $version = $this->versions[$row['package']] ?? null;

        return $version === null || $row['since'] === null || version_compare(ltrim($version, 'v'), ltrim((string) $row['since'], 'v'), '<=');
    }

    /** @param array{module: string, named: list<string>, default: ?string} $import */
    private static function importMatches(string $specifier, array $import): bool
    {
        [$package, $export] = array_pad(explode('#', $specifier, 2), 2, null);
        // "@/components/Kit#Name": a named import from kit's barrel, @kit/ui or a deep @/components/Kit/** module alike.
        if ($export !== null && in_array($package, self::KIT_ALIASES, true)) {
            return self::importsExport($package, $export, $import);
        }
        if ($import['module'] !== $package && ! str_starts_with($import['module'], $package.'/')) {
            return false;
        }

        return $export === null || in_array($export, $import['named'], true);
    }

    /** @param array{module: string, named: list<string>, default: ?string} $import */
    private static function importsExport(string $alias, string $name, array $import): bool
    {
        $aliases = in_array($alias, self::KIT_ALIASES, true) ? self::KIT_ALIASES : [$alias];
        foreach ($aliases as $candidate) {
            if (in_array($import['module'], [$candidate, $candidate.'/index', $candidate.'/index.ts', $candidate.'/index.js'], true)) {
                if (in_array($name, $import['named'], true)) {
                    return true;
                }
            } elseif (str_starts_with($import['module'], $candidate.'/')) {
                $file = (string) preg_replace('~\.(vue|ts|js)$~', '', basename($import['module']));
                if (in_array($name, $import['named'], true) || ($import['default'] !== null && $file === $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every criterion given must hold (a list matches when any of its entries does).
     *
     * @param  array<string, mixed>  $signal
     * @param  array{name: string, extends: ?string, implements: list<string>}  $class
     */
    private static function classMatches(array $signal, array $class): bool
    {
        $normalise = fn (array $names): array => array_map(fn (string $name): string => ltrim($name, '\\'), $names);
        if (($signal['extends'] ?? []) !== [] && ! in_array($class['extends'], $normalise($signal['extends']), true)) {
            return false;
        }
        if (($signal['implements'] ?? []) !== [] && array_intersect($class['implements'], $normalise($signal['implements'])) === []) {
            return false;
        }
        if (($signal['names'] ?? []) !== [] && array_filter($signal['names'], fn (string $glob): bool => App::glob($glob, $class['name'])) === []) {
            return false;
        }

        return true;
    }

    /** A template-tag signal's where/except, as evidence text: ` where type=file except type=hidden|search`. */
    private static function filters(array $signal): string
    {
        $text = '';
        foreach (['where', 'except'] as $field) {
            foreach ($signal[$field] ?? [] as $name => $values) {
                $text .= " {$field} {$name}=".implode('|', $values);
            }
        }

        return $text;
    }

    /** @return array{file: string, line: ?int, detail: string} */
    private static function evidence(string $file, ?int $line, string $detail): array
    {
        return ['file' => $file, 'line' => $line, 'detail' => $detail];
    }

    /** @param array<string, mixed> $entry @return array<string, mixed> */
    private static function cite(array $entry): array
    {
        return ['rule' => $entry['rule'], 'target' => $entry['target'], 'reason' => $entry['reason'], 'expires' => $entry['expires'], 'file' => $entry['file'], 'line' => $entry['line']];
    }

    /** @param array<string, mixed> $evidence @return array{file: string, line: ?int} */
    private static function where(array $evidence): array
    {
        return ['file' => (string) $evidence['file'], 'line' => $evidence['line'] ?? null];
    }

    /** @param array<string, list<string>> $uses */
    public static function expects(array $uses): string
    {
        return implode('; ', array_map(fn (string $kind, array $values): string => $kind.' '.implode(', ', $values), array_keys($uses), $uses));
    }

    /** @return array<string, string> composer name => installed version, from vendor/composer/installed.json */
    private static function installedVersions(string $base): array
    {
        $file = $base.'/vendor/composer/installed.json';
        $installed = is_file($file) && is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        $packages = is_array($installed) ? ($installed['packages'] ?? $installed) : [];
        $versions = [];
        foreach (is_array($packages) ? $packages : [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                $versions[$package['name']] = ltrim($package['version'], 'v');
            }
        }

        return $versions;
    }

    private static function appName(App $app): string
    {
        $composer = $app->json('composer.json');

        return is_string($composer['name'] ?? null) ? $composer['name'] : basename($app->base);
    }

    private function relative(string $path): string
    {
        [$path, $base] = [str_replace('\\', '/', $path), str_replace('\\', '/', $this->app->base)];

        return str_starts_with($path, $base.'/') ? substr($path, strlen($base) + 1) : $path;
    }
}
