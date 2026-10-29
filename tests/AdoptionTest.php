<?php

declare(strict_types=1);

use Tey\Standards\Adoption\AdoptionError;
use Tey\Standards\Adoption\Cli;
use Tey\Standards\Adoption\App;
use Tey\Standards\Adoption\Facts;
use Tey\Standards\Adoption\Inventory;
use Tey\Standards\Adoption\Lists;
use Tey\Standards\Adoption\Scanner;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;
use Tey\Standards\RuleUnavailable;
use Tey\Standards\Enforcement\CheckReport;

function adoptionFixture(string $path = ''): string
{
    return dirname(__DIR__).'/fixtures/Adoption'.($path === '' ? '' : '/'.$path);
}

function adoptionConfiguration(string $base, array $allowlist = []): Configuration
{
    return new Configuration(...array_replace(get_object_vars(App::bare($base)), [
        'classRoots' => ['app' => 'App'],
        'sourceRoots' => ['app'],
        'pageRoots' => ['resources/js/pages'],
        'allowlist' => $allowlist,
    ]));
}

/**
 * A fixture inventory reads as the installed (vendor) copy when the fixture app installs its package, as it would in a real app, and as an
 * explicit --inventory otherwise.
 *
 * @param list<string>|null $inventories fixture inventory names; null reads kit.json
 */
function adoptionReport(string $case, string $mode = 'report', ?array $inventories = null, array $adoptedTables = [], ?Configuration $config = null, bool $recordsUnreadable = false): array
{
    $base = adoptionFixture($case);
    $config ??= is_file($base.'/tests/Arch/configuration.php') ? CheckReport::configuration($base, 'tests/Arch/configuration.php') : adoptionConfiguration($base);
    $loaded = [];
    foreach ($inventories ?? ['kit'] as $name) {
        $path = adoptionFixture('inventories/'.$name.'.json');
        $inventory = Inventory::load($path, 'option');
        $loaded[] = Scanner::installedIn($base, $inventory->composer()) ? Inventory::load($path, 'vendor') : $inventory;
    }
    usort($loaded, fn (Inventory $a, Inventory $b): int => $a->composer() <=> $b->composer());

    return (new Scanner(new App($base, $config), $loaded, $config, new DateTimeImmutable('2026-10-29'), $adoptedTables, 'tests/Arch/configuration.php', $recordsUnreadable))->report($mode);
}

/** @param array<string, mixed> $mutations surface id => field overrides, or '*' => top-level overrides */
function adoptionInventory(array $mutations = [], string $name = 'kit'): array
{
    $data = json_decode((string) file_get_contents(adoptionFixture('inventories/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
    foreach ($mutations as $key => $value) {
        if ($key === '*') {
            $data = array_replace($data, $value);

            continue;
        }
        foreach ($data['surfaces'] as $index => $surface) {
            if ($surface['id'] === $key) {
                $data['surfaces'][$index] = array_replace($surface, $value);
            }
        }
    }

    return $data;
}

function adoptionRows(array $report): array
{
    return array_column(array_map(fn (array $row): array => [$row['package'].' '.$row['id'], $row['status']], $report['rows']), 1, 0);
}

function adoptionWhere(array $evidence): string
{
    return $evidence['file'].($evidence['line'] === null ? '' : ':'.$evidence['line']);
}

test('a fully adopting app reads adopted on every applicable surface, through each import form, with no gaps', function () {
    $report = adoptionReport('adopted', 'compliance', ['kit', 'reports']);

    expect(adoptionRows($report))->toBe([
        'acme/reports export.csv' => 'adopted',
        'acme/reports export.pdf' => 'adopted',
        'tey/kit shell.workspace' => 'adopted',
        'tey/kit layout.page' => 'adopted',
        'tey/kit navigation.search' => 'adopted',
        'tey/kit form.inputs' => 'adopted',
        'tey/kit data.table' => 'adopted',
        'tey/kit feedback.toast' => 'adopted',
        'tey/kit workspaces.tenancy' => 'adopted',
        'tey/kit mail.layout' => 'adopted',
        'tey/kit mcp.server' => 'adopted',
    ])
        ->and($report['gaps'])->toBe([])
        ->and($report['blocking'])->toBe([])
        ->and($report['exit_code'])->toBe(0);

    $evidence = array_column(array_map(fn (array $row): array => [$row['id'], adoptionWhere($row['evidence'][0]).' '.$row['evidence'][0]['detail']], $report['rows']), 1, 0);
    // "@/components/Kit:X" matches the barrel, @kit/ui, a deep default import and a deep named import alike.
    expect($evidence['layout.page'])->toBe('resources/js/pages/Dashboard/Page.vue:3 imports Page from @/components/Kit')
        ->and($evidence['data.table'])->toBe('resources/js/pages/Dashboard/Page.vue:5 imports DataTable from @kit/ui')
        ->and($evidence['navigation.search'])->toBe('resources/js/pages/Dashboard/Page.vue:4 imports SearchBox from @/components/Kit/SearchBox.vue')
        ->and($evidence['feedback.toast'])->toBe('resources/js/pages/Dashboard/Page.vue:6 imports Toaster from @/components/Kit/Feedback')
        ->and($evidence['mail.layout'])->toBe('app/Mail/WelcomeMail.php:6 references Tey\\Kit\\Mail\\FluentMailMessage')
        ->and($evidence['mcp.server'])->toBe('resources/js/pages/Mcp/Authorize/Page.vue feature file present');
});

test('hand-rolled reads from every signal kind, from a {kind: rule} reference to ui.kit-shell, and from a replaces mapping', function () {
    $rows = [];
    foreach (adoptionReport('hand-rolled')['rows'] as $row) {
        $rows[$row['id']] = [$row['status'], array_map(fn (array $e): string => adoptionWhere($e).' '.$e['detail'], $row['evidence'])];
    }

    expect($rows)->toBe([
        'shell.workspace' => ['hand-rolled', ['resources/js/layouts/AppHeader.vue:6 root <header> with route logout, <UserMenu>']],
        'layout.page' => ['hand-rolled', ['resources/js/layouts/AppHeader.vue:6 [ui.kit-shell] signed-in <header> outside kit\'s shell']],
        'navigation.search' => ['hand-rolled', ['resources/js/components/InvoiceSearch.vue file name matches resources/js/**/*Search*.vue']],
        'form.inputs' => ['hand-rolled', ['resources/js/components/SelectCountry.vue:2 imports vue-multiselect']],
        'data.table' => ['hand-rolled', ['resources/js/pages/Orders/Page.vue:11 1 × <table> in a page']],
        // No signal fired and no use matched, but package.json carries a library it replaces.
        'feedback.toast' => ['hand-rolled', ['package.json depends on vue-toastification']],
        'workspaces.tenancy' => ['hand-rolled', ['database/migrations/2026_01_01_000000_create_teams_table.php:11 creates table teams', 'composer.json requires stancl/tenancy']],
        'mail.layout' => ['hand-rolled', ['resources/views/mail/welcome.blade.php a mail view without kit::mail']],
        'mcp.server' => ['hand-rolled', ['app/Mcp/Servers/AppServer.php:7 class AppServer extends Laravel\\Mcp\\Server']],
    ]);
});

test('hand-rolled beats uses: a surface whose kit components are imported still reads hand-rolled when a signal fires', function () {
    $rows = array_column(adoptionReport('hand-rolled')['rows'], null, 'id');

    foreach (['layout.page' => 'imports Page from @/components/Kit', 'data.table' => 'imports DataTable from @/components/Kit', 'shell.workspace' => 'feature file present'] as $id => $match) {
        expect($rows[$id]['status'])->toBe('hand-rolled')
            ->and(array_column($rows[$id]['matches'], 'detail'))->toContain($match)
            ->and($rows[$id]['signals'])->not->toBe([]);
    }
});

test('missing, n/a from applies, and a surface whose feature is not installed', function () {
    $report = adoptionReport('missing', 'report', ['kit', 'reports']);
    $rows = array_column($report['rows'], null, 'id');

    expect(adoptionRows($report))->toBe([
        'acme/reports export.csv' => 'n/a',
        'acme/reports export.pdf' => 'missing',
        'tey/kit shell.workspace' => 'missing',
        'tey/kit layout.page' => 'missing',
        'tey/kit navigation.search' => 'n/a',
        'tey/kit form.inputs' => 'n/a',
        'tey/kit data.table' => 'n/a',
        'tey/kit feedback.toast' => 'missing',
        'tey/kit workspaces.tenancy' => 'n/a',
        'tey/kit mail.layout' => 'n/a',
        'tey/kit mcp.server' => 'n/a',
    ])
        // The shell's feature file is present, but config/kit.php lists no `shell` feature: missing, not adopted.
        ->and($rows['shell.workspace']['note'])->toBe('feature shell is not installed')
        ->and(array_column($rows['shell.workspace']['matches'], 'detail'))->toBe(['renders <PrimaryNav>', 'feature file present'])
        ->and($rows['export.pdf']['note'])->toBe('acme/reports is not installed')
        ->and($report['facts']['tey/kit']['has-signed-in-pages'])->toBe(['value' => true, 'evidence' => 'routes/web.php:5', 'computed' => ['analysis' => 'signed-in-pages']])
        ->and($report['facts']['tey/kit']['has-search'])->toMatchArray(['value' => false, 'evidence' => null]);
});

test('a hand-rolled signal is evidence on its own: it reads hand-rolled when the package is not installed, and only a silent surface reads missing', function () {
    $report = adoptionReport('uninstalled');
    $rows = array_column($report['rows'], null, 'id');

    expect($report['inventories'][0]['installed'])->toBeFalse()
        // Positive: vue-toastification fires feedback.toast's signal although tey/kit is not installed.
        ->and($rows['feedback.toast']['status'])->toBe('hand-rolled')
        ->and($rows['feedback.toast']['installed'])->toBeFalse()
        ->and($rows['feedback.toast']['note'])->toBe('tey/kit is not installed')
        ->and(array_map(adoptionWhere(...), $rows['feedback.toast']['evidence']))->toBe(['resources/js/pages/Orders/Page.vue:2'])
        // Negative: an applicable surface with no signal still reads missing, with no evidence; n/a still wins over both.
        ->and($rows['shell.workspace']['status'])->toBe('missing')
        ->and($rows['shell.workspace']['evidence'])->toBe([])
        ->and($rows['navigation.search']['status'])->toBe('n/a');
});

test('kit-owned files never count as adoption; an edited lineage copy is the app\'s own', function () {
    $rows = array_column(adoptionReport('kit-owned')['rows'], null, 'id');

    // components/Kit/Page.vue imports PageEnd and renders <PrimaryNav>, and the unedited Dashboard page imports Page and renders <PrimaryNav>: none of it counts.
    expect($rows['shell.workspace']['status'])->toBe('missing')
        ->and($rows['shell.workspace']['matches'])->toBe([])
        ->and(array_column($rows['layout.page']['evidence'], 'file'))->toBe(['resources/js/pages/Workspace/Settings/Page.vue'])
        ->and($rows['feedback.toast']['status'])->toBe('adopted')
        ->and($rows['feedback.toast']['evidence'][0]['file'])->toBe('resources/js/pages/Workspace/Settings/Page.vue');
});

test('migration signals count Schema::create only, and a table recorded in kit_adoptions reads adopted', function () {
    $row = fn (array $tables): array => array_column(adoptionReport('migrations', adoptedTables: $tables)['rows'], null, 'id')['workspaces.tenancy'];

    expect($row([]))->toMatchArray(['status' => 'hand-rolled'])
        // The teams migration alters with Schema::table: never a signal.
        ->and(array_column($row([])['evidence'], 'detail'))->toBe(['creates table workspaces'])
        ->and($row(['workspaces'])['status'])->toBe('adopted')
        ->and($row(['workspaces'])['evidence'][0])->toBe(['file' => 'database/migrations/2026_01_01_000000_create_workspaces_table.php', 'line' => 11, 'detail' => 'creates table workspaces, recorded in kit_adoptions (adopted in place)']);
});

test('gaps: a third-party import, a generic name and a package.json library; a domain component is not one; a replaces match maps instead', function () {
    $report = adoptionReport('hand-rolled');

    expect(array_map(fn (array $gap): array => [$gap['kind'], $gap['subject'], adoptionWhere($gap), $gap['detail']], $report['gaps']))->toBe([
        ['component', 'resources/js/components/Button.vue', 'resources/js/components/Button.vue', 'app component: generic component name Button'],
        ['component', 'resources/js/components/SalesChart.vue', 'resources/js/components/SalesChart.vue:3', 'app component: imports chart.js'],
        ['library', 'npm:chart.js', 'package.json', 'depends on chart.js'],
    ]);

    $rows = array_column($report['rows'], null, 'id');
    // SelectCountry.vue (generic Select*, imports vue-multiselect) and the vue-multiselect dependency map to form.inputs; InvoiceTable.vue is domain code.
    expect(array_column($rows['form.inputs']['replaced'], 'detail'))->toBe(['app component: imports vue-multiselect', 'depends on vue-multiselect'])
        ->and(array_column($report['gaps'], 'subject'))->not->toContain('resources/js/components/InvoiceTable.vue')
        ->and(array_column($report['gaps'], 'subject'))->not->toContain('npm:vite')
        ->and(array_column($report['gaps'], 'subject'))->not->toContain('npm:vue');
});

test('the generic-name, framework-runtime and tooling lists are closed and versioned', function () {
    expect(Lists::VERSION)->toBe(3)
        ->and(Lists::generic('Button'))->toBeTrue()
        ->and(Lists::generic('InputText'))->toBeTrue()
        ->and(Lists::generic('MenuBar'))->toBeTrue()
        ->and(Lists::generic('InvoiceTable'))->toBeFalse()
        ->and(Lists::generic('Buttons'))->toBeFalse()
        ->and(Lists::runtime('@inertiajs/vue3'))->toBeTrue()
        ->and(Lists::runtime('@vueuse/core'))->toBeTrue()
        ->and(Lists::runtime('vue-router'))->toBeFalse()
        ->and(Lists::tooling('@vitejs/plugin-vue'))->toBeTrue()
        ->and(Lists::tooling('chart.js'))->toBeFalse();
});

test('--compliance lists every blocking row in a stable order: hand-rolled, missing, exempt and gap, each with its evidence', function () {
    $report = adoptionReport('mixed', 'compliance');
    $config = 'tests/Arch/configuration.php';
    $list = array_map(fn (array $item): array => [$item['kind'], $item['package'], $item['subject'], $item['evidence'] === [] ? 'expects '.$item['expects'] : implode(', ', array_map('adoptionWhere', $item['evidence']))], $report['blocking']);

    expect($list)->toBe([
        ['hand-rolled', 'tey/kit', 'shell.workspace', 'resources/js/layouts/TopBar.vue:2'],
        ['exempt', 'tey/kit', 'layout.page', $config.':30'],
        ['hand-rolled', 'tey/kit', 'navigation.search', 'resources/js/components/GlobalSearch.vue'],
        ['missing', 'tey/kit', 'form.inputs', 'expects imports @/components/Kit:Input, @/components/Kit:Field'],
        ['missing', 'tey/kit', 'data.table', 'expects imports @/components/Kit:DataTable'],
        ['missing', 'tey/kit', 'feedback.toast', 'expects imports @/components/Kit:Toaster'],
        ['missing', 'tey/kit', 'mail.layout', 'expects classes Tey\\Kit\\Mail\\FluentMailMessage'],
        ['gap', null, 'resources/js/components/MapView.vue', 'resources/js/components/MapView.vue:2'],
        ['exempt', null, 'resources/js/components/Modal.vue', $config.':31'],
        ['gap', null, 'resources/js/components/Tooltip.vue', 'resources/js/components/Tooltip.vue'],
        ['gap', null, 'npm:dayjs', 'package.json'],
        ['gap', null, 'npm:leaflet', 'package.json'],
        ['exempt', null, 'adoption.tey/kit.data.table data.table', $config.':32'],
        ['exempt', null, 'adoption.tey/kit.mail.layout mail.layout', $config.':33'],
        ['exempt', null, 'adoption.tey/kit.workspaces.tenancy workspaces.tenancy', $config.':34'],
    ])
        ->and(array_column(array_slice($report['blocking'], -3), 'problem'))->toBe([
            'expired 2026-10-15: fix the row, or extend `expires` with a new reason',
            'no expiry: give it an `expires` Y-m-d date',
            'stale: no hand-rolled, missing or gap row needs it; delete the entry',
        ])
        ->and($report['exit_code'])->toBe(1)
        ->and($report['summary'])->toBe(['adopted' => 0, 'hand-rolled' => 2, 'missing' => 4, 'n/a' => 2, 'exempt' => 1, 'gaps' => 4, 'exempt_gaps' => 1]);
});

test('--ci accepts valid exemptions and only warns on a missing surface new in this release; --compliance blocks both', function () {
    $ci = adoptionReport('mixed', 'ci');

    expect(array_map(fn (array $item): string => $item['kind'].' '.$item['subject'], $ci['blocking']))->toBe([
        'hand-rolled shell.workspace',
        'hand-rolled navigation.search',
        'missing form.inputs',
        'missing data.table',
        'missing mail.layout',
        'gap resources/js/components/MapView.vue',
        'gap resources/js/components/Tooltip.vue',
        'gap npm:dayjs',
        'gap npm:leaflet',
        'exempt adoption.tey/kit.data.table data.table',
        'exempt adoption.tey/kit.mail.layout mail.layout',
        'exempt adoption.tey/kit.workspaces.tenancy workspaces.tenancy',
    ])
        ->and($ci['warnings'])->toBe(['tey/kit feedback.toast is missing; it is new in 0.7.0, so --ci only warns for that release (--compliance blocks it).'])
        ->and($ci['exit_code'])->toBe(1);

    // An app whose only failing row is a new surface: --ci passes with the warning, --compliance fails on it, the plain report exits 0.
    $base = adoptionFixture('adopted');
    $config = adoptionConfiguration($base);
    $inventory = Inventory::fromArray(adoptionInventory(['feedback.toast' => ['uses' => ['imports' => ['@/components/Kit:Sonner']]]]), 'kit.json', 'vendor');
    $run = fn (string $mode): array => (new Scanner(new App($base, $config), [$inventory], $config, new DateTimeImmutable('2026-10-29')))->report($mode);

    expect($run('ci'))->toMatchArray(['exit_code' => 0, 'blocking' => []])
        ->and($run('ci')['warnings'])->toHaveCount(1)
        ->and($run('compliance')['exit_code'])->toBe(1)
        ->and(array_column($run('compliance')['blocking'], 'subject'))->toBe(['feedback.toast'])
        ->and($run('report')['exit_code'])->toBe(0);
});

test('an exemption needs a reason, a date and an expiry; one that is expired or undated exempts nothing', function () {
    $base = adoptionFixture('missing');
    $entry = ['rule' => 'adoption.tey/kit.layout.page', 'target' => 'layout.page', 'check' => 'adoption', 'reason' => 'Moving next sprint.', 'date' => '2026-10-01', 'expires' => '2099-01-01'];
    $status = fn (array $entry): string => array_column(adoptionReport('missing', config: adoptionConfiguration($base, [$entry]))['rows'], null, 'id')['layout.page']['status'];

    expect($status($entry))->toBe('exempt')
        ->and($status(['expires' => '2026-10-28'] + $entry))->toBe('missing')
        ->and($status(array_diff_key($entry, ['expires' => true])))->toBe('missing')
        ->and(fn () => $status(['reason' => ''] + $entry))->toThrow(AdoptionError::class, 'needs a non-empty [reason]')
        ->and(fn () => $status(['date' => 'soon'] + $entry))->toThrow(AdoptionError::class, 'real Y-m-d date');
});

test('two inventories are read side by side, sorted by package, each with its version and origin', function () {
    $report = adoptionReport('adopted', 'report', ['reports', 'kit']);

    expect(array_map(fn (array $i): array => [$i['package'], $i['origin'], $i['installed'], $i['surfaces']], $report['inventories']))->toBe([
        ['acme/reports', 'vendor', true, 2],
        ['tey/kit', 'vendor', true, 9],
    ])
        ->and(fn () => Scanner::inventories(adoptionFixture('adopted'), [adoptionFixture('inventories/kit.json'), adoptionFixture('inventories/kit.json')]))
        ->toThrow(AdoptionError::class, 'Two --inventory files describe tey/kit');
});

test('it fails closed on an unknown format, field, signal kind or fact, a bad condition, and an unreadable inventory', function (array $data, string $message) {
    expect(fn () => Inventory::fromArray($data, 'kit.json'))->toThrow(AdoptionError::class, $message);
})->with([
    'unknown format' => [adoptionInventory(['*' => ['format' => 2]]), 'unknown format 2'],
    'unknown top-level field' => [adoptionInventory(['*' => ['kit' => '0.7.0']]), 'unknown field [kit]'],
    'unknown signal kind' => [adoptionInventory(['data.table' => ['handRolled' => [['kind' => 'css-class', 'classes' => ['table']]]]]), 'unknown signal kind "css-class"'],
    'unknown signal field' => [adoptionInventory(['data.table' => ['handRolled' => [['kind' => 'template-tag', 'tags' => ['table'], 'globs' => ['x']]]]]), 'unknown field [globs]'],
    'unknown surface field' => [adoptionInventory(['data.table' => ['owner' => 'kit']]), 'unknown field [owner]'],
    'unknown group' => [adoptionInventory(['data.table' => ['group' => 'forms-data']]), '"forms-data" is not one of'],
    'new without since' => [adoptionInventory(['data.table' => ['status' => 'new']]), 'a new surface needs `since`'],
    'applies names an undeclared fact' => [adoptionInventory(['data.table' => ['applies' => 'has-feeds']]), 'unknown fact [has-feeds]'],
    'an unknown fact analysis' => [adoptionInventory(['*' => ['facts' => [['id' => 'has-moods', 'description' => 'x', 'computed' => ['analysis' => 'mood-routes']]]]]), 'standards has no fact analysis "mood-routes"'],
    'a fact without computed' => [adoptionInventory(['*' => ['facts' => [['id' => 'has-moods', 'description' => 'x']]]]), 'missing required field [computed]'],
    'a fact computed from a rule' => [adoptionInventory(['*' => ['facts' => [['id' => 'has-moods', 'description' => 'x', 'computed' => ['signals' => [['kind' => 'rule', 'rule' => 'ui.kit-shell']]]]]]]), 'a fact cannot be computed from a rule signal'],
    'a fact signal of an unknown kind' => [adoptionInventory(['*' => ['facts' => [['id' => 'has-moods', 'description' => 'x', 'computed' => ['signals' => [['kind' => 'css-class']]]]]]]), 'unknown signal kind "css-class"'],
    'a fact computed two ways' => [adoptionInventory(['*' => ['facts' => [['id' => 'has-moods', 'description' => 'x', 'computed' => ['analysis' => 'mcp-server', 'signals' => []]]]]]), 'must be exactly one of'],
    'a malformed condition' => [adoptionInventory(['data.table' => ['applies' => ['all' => []]]]), 'must be a non-empty list of conditions'],
    'a rule that is not a standards rule' => [adoptionInventory(['layout.page' => ['handRolled' => [['kind' => 'rule', 'rule' => 'ui.nope']]]]), 'rule [ui.nope] is not a standards rule'],
    'an empty php-class signal' => [adoptionInventory(['mcp.server' => ['handRolled' => [['kind' => 'php-class']]]]), 'needs a non-empty extends, implements or names'],
]);

test('an unreadable or invalid inventory file fails closed', function () {
    $directory = sys_get_temp_dir().'/adoption-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/broken.json', '{"format": 1,');

    try {
        expect(fn () => Inventory::load($directory.'/absent.json', 'option'))->toThrow(AdoptionError::class, 'Cannot read the adoption inventory')
            ->and(fn () => Inventory::load($directory.'/broken.json', 'option'))->toThrow(AdoptionError::class, 'is not valid JSON')
            ->and(fn () => Scanner::inventories($directory, ['absent.json']))->toThrow(AdoptionError::class, 'Cannot read the adoption inventory');
        expect(fn () => (new Scanner(new App($directory), [], null, new DateTimeImmutable('2026-10-29')))->report())->toThrow(AdoptionError::class, 'No adoption inventory to read');
    } finally {
        array_map('unlink', glob($directory.'/*') ?: []);
        rmdir($directory);
    }
});

test('a vendor package shipping resources/adoption/surfaces.json is read, and an explicit --inventory replaces it', function () {
    $directory = sys_get_temp_dir().'/adoption-'.bin2hex(random_bytes(4));
    mkdir($directory.'/vendor/acme/reports/resources/adoption', 0777, true);
    copy(adoptionFixture('inventories/reports.json'), $directory.'/vendor/acme/reports/resources/adoption/surfaces.json');

    try {
        expect(array_map(fn (Inventory $i): string => $i->composer().' '.$i->origin, Scanner::inventories($directory, [])))->toBe(['acme/reports vendor'])
            ->and(array_map(fn (Inventory $i): string => $i->composer().' '.$i->origin, Scanner::inventories($directory, [adoptionFixture('inventories/reports.json')])))->toBe(['acme/reports option']);
    } finally {
        unlink($directory.'/vendor/acme/reports/resources/adoption/surfaces.json');
        foreach (['vendor/acme/reports/resources/adoption', 'vendor/acme/reports/resources', 'vendor/acme/reports', 'vendor/acme', 'vendor', ''] as $path) {
            rmdir($directory.($path === '' ? '' : '/'.$path));
        }
    }
});

test('tey/kit\'s real inventory (feat/adoption-surfaces 791fadb) reads with the same reader and scans an app', function () {
    $inventory = Inventory::load(adoptionFixture('inventories/kit-791fadb.json'), 'option');
    $data = json_decode((string) file_get_contents(adoptionFixture('inventories/kit-791fadb.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($inventory->composer())->toBe('tey/kit')
        ->and($inventory->package['alias'])->toBe('@/components/Kit')
        ->and($inventory->surfaces)->toHaveCount(count($data['surfaces']))
        ->and($inventory->facts)->toHaveCount(count($data['facts']))
        ->and(array_values(array_unique(array_filter(array_map(fn (array $fact): ?string => $fact['computed']['analysis'] ?? null, $inventory->facts)))))->toEqualCanonicalizing(Facts::ANALYSES)
        ->and(array_values(array_unique(array_merge(...array_map(fn (array $s): array => array_column($s['handRolled'], 'kind'), $inventory->surfaces)))))
        ->toEqualCanonicalizing(array_keys(Inventory::SIGNALS));

    $base = adoptionFixture('adopted');
    $config = adoptionConfiguration($base);
    $report = (new Scanner(new App($base, $config), [Inventory::load(adoptionFixture('inventories/kit-791fadb.json'), 'vendor')], $config, new DateTimeImmutable('2026-10-29')))->report('compliance');

    expect($report['rows'])->toHaveCount(count($data['surfaces']))
        ->and(array_keys($report['facts']['tey/kit']))->toBe(array_column($data['facts'], 'id'))
        ->and(array_unique(array_column($report['rows'], 'status')))->each->toBeIn(Scanner::STATUSES);
});

test('--json is a stable format-1 document with rows, evidence, gaps, facts, inventories and the blocking list', function () {
    $report = adoptionReport('mixed', 'ci', ['kit', 'reports']);

    expect(array_keys($report))->toBe(['format', 'app', 'generated_at', 'mode', 'lists', 'overridden', 'inventories', 'facts', 'adopted_tables', 'rows', 'gaps', 'exemptions', 'warnings', 'summary', 'blocking', 'exit_code'])
        ->and($report)->toMatchArray(['format' => 1, 'app' => 'fixture/mixed', 'generated_at' => '2026-10-29', 'mode' => 'ci', 'lists' => 3])
        ->and(array_keys($report['rows'][0]))->toBe(['package', 'id', 'layer', 'group', 'label', 'feature', 'installed', 'applies', 'status', 'underlying', 'new', 'since', 'note', 'cause', 'evidence', 'signals', 'matches', 'replaced', 'uses', 'docs', 'exemption', 'skipped'])
        ->and(array_keys($report['gaps'][0]))->toBe(['subject', 'kind', 'file', 'line', 'detail', 'files', 'exemption'])
        ->and(array_keys($report['blocking'][0]))->toBe(['kind', 'package', 'subject', 'evidence', 'expects', 'cause'])
        ->and(array_keys($report['inventories'][0]))->toBe(['package', 'npm', 'alias', 'origin', 'source', 'version', 'installed', 'surfaces'])
        ->and(array_keys($report['facts']))->toBe(['acme/reports', 'tey/kit'])
        ->and(array_keys($report['facts']['tey/kit']))->toBe(['has-signed-in-pages', 'has-search', 'has-forms', 'has-index-pages', 'has-page-actions', 'sends-mail', 'has-workspaces', 'has-mcp-server'])
        ->and($report['facts']['tey/kit']['sends-mail'])->toBe(['value' => true, 'evidence' => 'app/Mail/WelcomeMail.php:7', 'computed' => ['analysis' => 'mail-notifications']])
        ->and($report['facts']['tey/kit']['has-forms'])->toMatchArray(['value' => true, 'evidence' => 'resources/js/pages/Profile/Page.vue:8 1 × <form|input|select|textarea> in a page'])
        ->and(array_column($report['rows'], null, 'id')['layout.page']['exemption'])->toBe(['rule' => 'adoption.tey/kit.layout.page', 'target' => 'layout.page', 'reason' => 'The top bar moves to the kit shell in the next pass.', 'expires' => '2099-12-31', 'file' => 'tests/Arch/configuration.php', 'line' => 30])
        ->and(json_encode($report, JSON_THROW_ON_ERROR))->toBeString();
});

test('analysis facts are computed from static route, model and composer evidence, conservatively', function () {
    $facts = fn (string $case): array => array_map(fn (string $name): bool => (new Facts(new App(adoptionFixture($case))))->analysis($name)['value'], array_combine(Facts::ANALYSES, Facts::ANALYSES));

    expect($facts('adopted'))->toBe(['signed-in-pages' => true, 'public-pages' => false, 'auth-routes' => false, 'workspace-model' => true, 'mail-notifications' => true, 'mcp-server' => true, 'settings-routes' => false, 'search-route' => true, 'destructive-routes' => false])
        ->and($facts('missing'))->toMatchArray(['signed-in-pages' => true, 'search-route' => false, 'workspace-model' => false, 'mcp-server' => false, 'mail-notifications' => false])
        // A created workspaces table is a workspace model; no route file means no signed-in pages.
        ->and($facts('migrations'))->toMatchArray(['workspace-model' => true, 'signed-in-pages' => false])
        ->and((new Facts(new App(adoptionFixture('adopted'))))->analysis('workspace-model')['evidence'])->toBe('app/Models/Workspace.php:8')
        ->and(fn () => (new Facts(new App(adoptionFixture('adopted'))))->analysis('index-pages'))->toThrow(AdoptionError::class, 'no fact analysis [index-pages]');
});

test('signal facts fire in app-owned files: a kit import in the "@/components/Kit#Name" form, a template tag, and a vue-root with only requires.components', function () {
    $facts = fn (string $case): array => array_map(fn (array $fact): bool => $fact['value'], adoptionReport($case)['facts']['tey/kit']);

    // adopted imports DataTable from @kit/ui: the "@/components/Kit#DataTable" specifier still matches, so an adopting app has the need.
    expect(adoptionReport('adopted')['facts']['tey/kit']['has-index-pages']['evidence'])->toBe('resources/js/pages/Dashboard/Page.vue:5 imports @/components/Kit#DataTable')
        ->and($facts('adopted'))->toMatchArray(['has-forms' => true, 'has-index-pages' => true, 'has-page-actions' => false])
        // AppHeader.vue's root <header> renders <UserMenu>; the vue-root signal names components and a min, no routes.
        ->and($facts('hand-rolled'))->toMatchArray(['has-page-actions' => true, 'has-index-pages' => true, 'has-forms' => true])
        ->and(adoptionReport('hand-rolled')['facts']['tey/kit']['has-page-actions']['evidence'])->toBe('resources/js/layouts/AppHeader.vue:6 root <header> with <UserMenu>')
        // kit-owned files never make a signal fact true: the unedited Dashboard page renders nothing that counts.
        ->and($facts('missing'))->toMatchArray(['has-forms' => false, 'has-index-pages' => false, 'has-page-actions' => false]);
});

test('the standards:adoption command prints the table, --json and the exit codes per mode, and fails closed with exit 2', function () {
    $base = adoptionFixture('mixed');
    $container = new class($base) extends Illuminate\Container\Container
    {
        public function __construct(private string $root) {}

        public function basePath(string $path = ''): string
        {
            return $this->root.($path === '' ? '' : '/'.$path);
        }

        public function configPath(string $path = ''): string
        {
            return $this->basePath('config'.($path === '' ? '' : '/'.$path));
        }

        public function runningInConsole(): bool
        {
            return true;
        }

        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $container->instance('config', $config = new Illuminate\Config\Repository);
    $container->instance(Illuminate\Contracts\Config\Repository::class, $config);
    $previous = Illuminate\Container\Container::getInstance();
    Illuminate\Container\Container::setInstance($container);

    try {
        $provider = new Tey\Standards\StandardsServiceProvider($container);
        $provider->register();
        $provider->boot();
        $artisan = new Illuminate\Console\Application($container, new Illuminate\Events\Dispatcher($container), 'test');
        $artisan->resolveCommands([Tey\Standards\Enforcement\AdoptionCommand::class]);
        // mixed ships kit's inventory in vendor/tey/kit, as an installed kit would.
        $inventory = [];

        expect($artisan->call('standards:adoption', $inventory))->toBe(0)
            ->and($artisan->output())->toContain('tey/kit')->toContain('shell.workspace')->toContain('hand-rolled')->toContain('GAPS')
            ->toContain('feedback.toast')->toContain('(new in 0.7.0)')->toContain('Summary: 0 adopted, 2 hand-rolled, 4 missing, 2 n/a, 1 exempt; 4 gaps (1 exempt).');
        expect($artisan->call('standards:adoption', $inventory + ['--ci' => true]))->toBe(1)
            ->and($artisan->output())->toContain('--ci: 12 blocking, in order:')->toContain('Warning: tey/kit feedback.toast is missing');
        expect($artisan->call('standards:adoption', $inventory + ['--compliance' => true]))->toBe(1)
            ->and($artisan->output())->toContain('--compliance: 15 blocking, in order:')
            ->toContain('  missing  tey/kit form.inputs  expects imports @/components/Kit:Input, @/components/Kit:Field')
            ->toContain('  exempt  tey/kit layout.page  tests/Arch/configuration.php:30')
            ->toContain('Recipes: php artisan standards:pattern --recipe=ui-page.kit-adoption');
        expect($artisan->call('standards:adoption', $inventory + ['--json' => true, '--compliance' => true]))->toBe(1);
        $json = json_decode($artisan->output(), true);
        expect($json)->toMatchArray(['format' => 1, 'mode' => 'compliance', 'exit_code' => 1])
            ->and($json['blocking'])->toHaveCount(15);
        expect($artisan->call('standards:adoption', ['--inventory' => [adoptionFixture('inventories/absent.json')], '--json' => true]))->toBe(2);
        $json = json_decode($artisan->output(), true);
        expect($json)->toMatchArray(['format' => 1, 'exit_code' => 2])
            ->and($json['error'])->toContain('Cannot read the adoption inventory');
        // --inventory for an installed package: the gates fail closed; the plain report uses it and warns.
        $override = ['--inventory' => [adoptionFixture('inventories/kit.json')]];
        expect($artisan->call('standards:adoption', $override + ['--ci' => true]))->toBe(2)
            ->and($artisan->output())->toContain('describes tey/kit, which the app installs; --ci reads only the installed package\'s own inventory');
        expect($artisan->call('standards:adoption', $override + ['--compliance' => true]))->toBe(2);
        expect($artisan->call('standards:adoption', $override))->toBe(0)
            // Paths in the report use forward slashes on every OS.
            ->and($artisan->output())->toContain('Warning: vendor inventory for tey/kit overridden by '.str_replace('\\', '/', adoptionFixture('inventories/kit.json')));
        expect($artisan->call('standards:adoption', $override + ['--json' => true]))->toBe(0);
        $json = json_decode($artisan->output(), true);
        expect($json['overridden'])->toBe([['package' => 'tey/kit', 'by' => str_replace('\\', '/', adoptionFixture('inventories/kit.json')), 'vendor' => 'vendor/tey/kit/resources/adoption/surfaces.json']])
            ->and($json['inventories'][0]['origin'])->toBe('option');
        // A package the app doesn't install may be added in every mode (the calibration case).
        $add = ['--inventory' => [adoptionFixture('inventories/reports.json')], '--json' => true];
        foreach ([[], ['--ci' => true], ['--compliance' => true]] as $mode) {
            $artisan->call('standards:adoption', $add + $mode);
            $json = json_decode($artisan->output(), true);
            expect($json['exit_code'])->toBeIn([0, 1])
                ->and($json['overridden'])->toBe([])
                ->and(array_column($json['inventories'], 'package'))->toBe(['acme/reports', 'tey/kit'])
                ->and(array_column($json['rows'], 'status', 'id')['export.pdf'])->toBe('missing');
        }
        $config->set('standards.configuration', 'tests/Arch/missing.php');
        expect($artisan->call('standards:adoption', $inventory))->toBe(2)
            ->and($artisan->output())->toContain('failed closed')->toContain('not found');
    } finally {
        Illuminate\Container\Container::setInstance($previous);
    }
});

test('the report points at a pattern leaf that exists, and a kit adoption intent ranks it first', function () {
    $lookup = new Tey\Standards\Patterns\Lookup(Tey\Standards\Patterns\TreeCatalog::shipped());

    expect(Tey\Standards\Patterns\TreeCatalog::shipped()->node(Tey\Standards\Enforcement\AdoptionCommand::PATTERN))->not->toBeNull()
        ->and($lookup->recipe(Tey\Standards\Enforcement\AdoptionCommand::PATTERN))->toMatchArray(['type' => 'leaf', 'id' => 'ui-page.kit-adoption'])
        ->and($lookup->find(['intent' => 'kit adoption'])['candidates'][0]['id'])->toBe('ui-page.kit-adoption')
        ->and($lookup->find(['intent' => 'kit compliance'])['candidates'][0]['id'])->toBe('ui-page.kit-adoption');
});

test('an adoption.* allowlist entry is not an unregistered rule for the conformance gate', function () {
    $entry = ['rule' => 'adoption.tey/kit.layout.page', 'target' => 'layout.page', 'check' => 'adoption', 'reason' => 'x', 'date' => '2026-10-01', 'expires' => '2099-01-01'];

    expect(fn () => (new Tey\Standards\Ratchet([$entry]))->assertRules(['ui.kit-shell']))->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new Tey\Standards\Ratchet([['rule' => 'adoptions.x'] + $entry]))->assertRules(['ui.kit-shell']))->toThrow(InvalidArgumentException::class, 'unregistered rule [adoptions.x]');
});

test('kit\'s shell reads installed when session-ui is, from a features list or a lineage record, as ui.kit-shell reads it', function (string $how) {
    $directory = sys_get_temp_dir().'/adoption-'.bin2hex(random_bytes(4));
    $files = [
        'composer.json' => '{"name": "fixture/session-ui", "require": {"tey/kit": "^0.7"}}',
        'routes/web.php' => "<?php\n\nRoute::middleware(['auth'])->get('/dashboard', fn () => inertia('Dashboard/Page'));\n",
        'resources/js/pages/Workspace/Layout.vue' => "<template>\n    <slot />\n</template>\n",
    ];
    $files += $how === 'features'
        ? ['config/kit.php' => "<?php\n\nreturn ['features' => ['session-ui']];\n"]
        : ['.mod-maintenance/installed/'.hash('sha256', 'session-ui').'.json' => json_encode(['format' => 1, 'owner' => 'tey/mod:installed', 'artifact' => ['name' => 'tey/kit:session-ui', 'files' => []]])];
    foreach ($files as $path => $content) {
        is_dir(dirname($directory.'/'.$path)) || mkdir(dirname($directory.'/'.$path), 0777, true);
        file_put_contents($directory.'/'.$path, $content);
    }

    try {
        $config = adoptionConfiguration($directory);
        $row = fn (): array => array_column((new Scanner(new App($directory, $config), [Inventory::load(adoptionFixture('inventories/kit.json'), 'option')], $config, new DateTimeImmutable('2026-10-29')))->report()['rows'], null, 'id')['shell.workspace'];

        expect(Tey\Standards\Checks\KitShell::adopted($directory))->toBeTrue()
            ->and($row())->toMatchArray(['installed' => true, 'status' => 'adopted', 'note' => null]);

        // A literal features list without shell or session-ui means not installed, whatever the records say, as in ui.kit-shell.
        is_dir($directory.'/config') || mkdir($directory.'/config');
        file_put_contents($directory.'/config/kit.php', "<?php\n\nreturn ['features' => ['workspaces']];\n");
        expect($row())->toMatchArray(['installed' => false, 'status' => 'missing', 'note' => 'feature shell is not installed']);
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
})->with(['features', 'lineage']);

test('an --inventory replacing an installed package fails closed under --ci and --compliance, and warns in the plain report', function () {
    $base = adoptionFixture('missing');
    $config = adoptionConfiguration($base);
    $run = fn (string $mode, array $paths): array => (new Scanner(new App($base, $config), Scanner::inventories($base, $paths), $config, new DateTimeImmutable('2026-10-29')))->report($mode);
    $kit = adoptionFixture('inventories/kit.json');

    expect(fn () => $run('ci', [$kit]))->toThrow(AdoptionError::class, 'describes tey/kit, which the app installs; --ci reads only')
        ->and(fn () => $run('compliance', [$kit]))->toThrow(AdoptionError::class, '--compliance reads only the installed package\'s own inventory');

    $report = $run('report', [$kit]);
    $shown = str_replace('\\', '/', $kit);
    expect($report['overridden'])->toBe([['package' => 'tey/kit', 'by' => $shown, 'vendor' => null]])
        ->and($report['warnings'][0])->toBe('inventory for tey/kit (installed) overridden by '.$shown.'; --ci and --compliance refuse this.')
        ->and($report['rows'])->not->toBe([]);

    // acme/reports is not installed here: adding it is the calibration case, allowed in every mode.
    foreach (['report', 'ci', 'compliance'] as $mode) {
        expect($run($mode, [adoptionFixture('inventories/reports.json')]))->toMatchArray(['overridden' => []]);
    }
});

test('a created table reads hand-rolled with its cause when kit_adoptions could not be read', function () {
    $report = adoptionReport('migrations', 'compliance', recordsUnreadable: true);
    $item = array_values(array_filter($report['blocking'], fn (array $item): bool => $item['subject'] === 'workspaces.tenancy'))[0];

    expect(array_column($report['rows'], null, 'id')['workspaces.tenancy'])->toMatchArray(['status' => 'hand-rolled', 'cause' => 'adoption records unreadable (kit_adoptions)'])
        ->and($item['cause'])->toBe('adoption records unreadable (kit_adoptions)')
        ->and(Tey\Standards\Enforcement\AdoptionCommand::text($report))->toContain('  hand-rolled  tey/kit workspaces.tenancy  database/migrations/2026_01_01_000000_create_workspaces_table.php:11 — cause: adoption records unreadable (kit_adoptions)')
        // Readable records: no cause; another hand-rolled row never carries it.
        ->and(array_column(adoptionReport('migrations', 'compliance')['blocking'], 'cause'))->each->toBeNull()
        ->and(array_column(adoptionReport('hand-rolled', 'compliance', recordsUnreadable: true)['rows'], 'cause', 'id')['mcp.server'])->toBeNull();
});

test('template-tag never counts <input type="hidden">, in any quoting or case', function () {
    $directory = sys_get_temp_dir().'/adoption-'.bin2hex(random_bytes(4));
    mkdir($directory.'/resources/js/pages/Orders', 0777, true);
    file_put_contents($directory.'/composer.json', '{"name": "fixture/hidden", "require": {"tey/kit": "^0.7"}}');
    $page = $directory.'/resources/js/pages/Orders/Page.vue';
    $inventory = Inventory::fromArray(adoptionInventory(['*' => ['facts' => [], 'surfaces' => [[
        'id' => 'form.inputs', 'layer' => 'ui', 'group' => 'form', 'label' => 'Inputs', 'feature' => 'core', 'applies' => true,
        'uses' => ['imports' => ['@/components/Kit:Input']], 'handRolled' => [['kind' => 'template-tag', 'tags' => ['input'], 'min' => 1]], 'docs' => 'x', 'status' => 'stable',
    ]]]]), 'kit.json', 'vendor');
    $status = function () use ($directory, $inventory): string {
        $config = adoptionConfiguration($directory);

        return (new Scanner(new App($directory, $config), [$inventory], $config, new DateTimeImmutable('2026-10-29')))->report()['rows'][0]['status'];
    };

    try {
        file_put_contents($page, "<template>\n    <form>\n        <input type=\"hidden\" name=\"_token\">\n        <INPUT TYPE='HIDDEN' name=\"_method\" value=\"PUT\">\n        <input name=\"x\" type=hidden />\n        <input :value=\"id\" Type = \"Hidden\">\n    </form>\n</template>\n");
        expect($status())->toBe('missing');

        file_put_contents($page, "<template>\n    <form>\n        <input type=\"hidden\" name=\"_token\">\n        <input type=\"text\" name=\"hidden\" data-type=\"hidden\">\n    </form>\n</template>\n");
        expect($status())->toBe('hand-rolled');
    } finally {
        unlink($page);
        unlink($directory.'/composer.json');
        foreach (['resources/js/pages/Orders', 'resources/js/pages', 'resources/js', 'resources', ''] as $path) {
            rmdir($directory.($path === '' ? '' : '/'.$path));
        }
    }
});

const SKIPPED_REASON = 'Architecture checks require pestphp/pest ^4.7 || ^5.0, pestphp/pest-plugin-arch ^4.0 || ^5.0 and nikic/php-parser ^5.0 in require-dev.';

/**
 * Kit's surface shape: handRolled [{kind: rule, rule: ui.kit-shell}, {kind: file-name, …}], in a tree with signed-in pages. Returns
 * [directory, inventory file, cleanup].
 *
 * @return array{0: string, 1: string, 2: Closure}
 */
function skippedRuleTree(bool $handRolledFile): array
{
    $directory = sys_get_temp_dir().'/adoption-skip-'.bin2hex(random_bytes(4));
    mkdir($directory.'/resources/js/layouts', 0777, true);
    mkdir($directory.'/resources/js/pages/Dashboard', 0777, true);
    file_put_contents($directory.'/composer.json', '{"name": "fixture/skip"}');
    file_put_contents($directory.'/resources/js/pages/Dashboard/Page.vue', "<template>\n    <div>Dashboard</div>\n</template>\n");
    if ($handRolledFile) {
        file_put_contents($directory.'/resources/js/layouts/AppHeader.vue', "<template>\n    <header>Northwind Farms</header>\n</template>\n");
    }
    $inventory = $directory.'/inventory.json';
    file_put_contents($inventory, json_encode(adoptionInventory(['*' => ['facts' => [], 'surfaces' => [[
        'id' => 'layout.page', 'layer' => 'ui', 'group' => 'layout', 'label' => 'Page', 'feature' => 'core', 'applies' => true,
        'uses' => ['imports' => ['@/components/Kit:Page']],
        'handRolled' => [['kind' => 'rule', 'rule' => 'ui.kit-shell'], ['kind' => 'file-name', 'globs' => ['resources/js/**/*Header*.vue']]],
        'docs' => 'x', 'status' => 'stable',
    ]]]]), JSON_THROW_ON_ERROR));

    return [$directory, $inventory, function () use ($directory): void {
        (new Illuminate\Filesystem\Filesystem)->deleteDirectory($directory);
    }];
}

$unavailable = fn (string $rule): array => throw new RuleUnavailable(SKIPPED_REASON);

test('the skipped reason is Conformance\'s missing-dependency message, and Conformance throws it typed', function () {
    expect(Conformance::missingDependencies())->toBeNull()
        ->and((new ReflectionClass(RuleUnavailable::class))->isSubclassOf(LogicException::class))->toBeTrue();
});

test('plain mode: an unrunnable rule signal is skipped with its reason, the file-name signal decides the row, exit 0', function () use ($unavailable) {
    [$directory, $inventory, $cleanup] = skippedRuleTree(true);
    try {
        $json = Cli::run(['--base', $directory, '--inventory', $inventory, '--json'], $directory, $unavailable);
        $row = json_decode($json['stdout'], true, flags: JSON_THROW_ON_ERROR)['rows'][0];
        $text = Cli::run(['--base', $directory, '--inventory', $inventory], $directory, $unavailable);

        expect($json['exit'])->toBe(0)
            ->and($row['status'])->toBe('hand-rolled')
            ->and(array_column($row['evidence'], 'file'))->toBe(['resources/js/layouts/AppHeader.vue'])
            ->and($row['skipped'])->toBe([['signal' => 0, 'kind' => 'rule', 'rule' => 'ui.kit-shell', 'reason' => SKIPPED_REASON]])
            ->and($text['exit'])->toBe(0)
            ->and($text['stdout'])->toMatch('~layout\.page .*hand-rolled .*skipped \[1\]~')
            ->and($text['stdout'])->toContain('[1] tey/kit layout.page: rule ui.kit-shell skipped (could not run): '.SKIPPED_REASON);

        // Without the file the other signal does not fire: the row reads from uses/installed, not from the skipped rule.
        unlink($directory.'/resources/js/layouts/AppHeader.vue');
        $row = json_decode(Cli::run(['--base', $directory, '--inventory', $inventory, '--json'], $directory, $unavailable)['stdout'], true, flags: JSON_THROW_ON_ERROR)['rows'][0];
        expect($row['status'])->toBe('missing')->and($row['skipped'])->toHaveCount(1);
    } finally {
        $cleanup();
    }
});

test('--ci and --compliance fail closed with exit 2 naming the surface, rule and reason', function (string $flag) use ($unavailable) {
    [$directory, $inventory, $cleanup] = skippedRuleTree(true);
    try {
        $text = Cli::run(['--base', $directory, '--inventory', $inventory, $flag], $directory, $unavailable);
        $json = Cli::run(['--base', $directory, '--inventory', $inventory, $flag, '--json'], $directory, $unavailable);

        expect($text['exit'])->toBe(2)
            ->and($text['stderr'])->toContain('tey/kit layout.page', 'rule [ui.kit-shell]', SKIPPED_REASON)
            ->and($json['exit'])->toBe(2)
            ->and(json_decode($json['stdout'], true)['error'])->toContain('layout.page', 'ui.kit-shell', SKIPPED_REASON);
    } finally {
        $cleanup();
    }
})->with(['--ci', '--compliance']);

test('any other runner failure is not a skip: it fails closed in plain mode too', function () {
    [$directory, $inventory, $cleanup] = skippedRuleTree(true);
    try {
        $result = Cli::run(['--base', $directory, '--inventory', $inventory], $directory, fn (string $rule): array => throw new LogicException('boom'));
        expect($result['exit'])->toBe(2)->and($result['stderr'])->toContain('Rule [ui.kit-shell] could not run for a handRolled signal: boom');
    } finally {
        $cleanup();
    }
});

test('a rule that runs is evidence as before, with no skipped entries', function () {
    $report = adoptionReport('hand-rolled');
    $row = array_column($report['rows'], null, 'id')['layout.page'];

    expect($row['status'])->toBe('hand-rolled')
        ->and(array_column($row['evidence'], 'detail'))->toBe(["[ui.kit-shell] signed-in <header> outside kit's shell"])
        ->and(array_merge(...array_column($report['rows'], 'skipped')))->toBe([])
        ->and(implode("\n", Tey\Standards\Enforcement\AdoptionCommand::text($report)))->not->toContain('skipped');
});
