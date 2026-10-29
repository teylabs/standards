<?php

declare(strict_types=1);

use Tey\Standards\Adoption\App;
use Tey\Standards\Adoption\Code;
use Tey\Standards\Adoption\Facts;
use Tey\Standards\Adoption\Inventory;
use Tey\Standards\Adoption\Lists;
use Tey\Standards\Adoption\Scanner;
use Tey\Standards\Configuration;

/*
 * Fixes from calibrating standards:adoption against legacy Laravel apps. Each fixture tree under fixtures/Adoption/calibration is made up.
 */

function calibrationBase(string $case): string
{
    return dirname(__DIR__).'/fixtures/Adoption/calibration/'.$case;
}

function calibrationConfiguration(string $base, array $overrides = []): Configuration
{
    return new Configuration(...array_replace(get_object_vars(App::bare($base)), ['classRoots' => ['app' => 'App'], 'sourceRoots' => ['app']], $overrides));
}

/**
 * A one-surface-per-signal tey/kit inventory: each `$surfaces` entry is id => handRolled signals, applying everywhere.
 *
 * @param  array<string, list<array<string, mixed>>>  $surfaces
 * @param  list<array<string, mixed>>  $facts
 */
function calibrationInventory(array $surfaces, array $facts = []): Inventory
{
    $data = ['format' => 1, 'package' => ['composer' => 'tey/kit', 'alias' => '@/components/Kit'], 'facts' => $facts, 'nonSurfaces' => [], 'surfaces' => []];
    foreach ($surfaces as $id => $signals) {
        $data['surfaces'][] = ['id' => $id, 'layer' => 'ui', 'group' => 'layout', 'label' => $id, 'feature' => 'core', 'applies' => true, 'uses' => ['imports' => ['@/components/Kit:Thing']], 'handRolled' => $signals, 'docs' => 'docs/x.md', 'status' => 'stable'];
    }

    return Inventory::fromArray($data, 'calibration.json');
}

/** @return array<string, mixed> */
function calibrationReport(string $case, Inventory $inventory, ?Configuration $config = null): array
{
    $base = calibrationBase($case);
    $config ??= calibrationConfiguration($base);

    return (new Scanner(new App($base, $config), [$inventory], $config, new DateTimeImmutable('2026-10-29')))->report();
}

/** @return array<string, list<string>> surface id => evidence files */
function calibrationEvidence(array $report): array
{
    return array_column(array_map(fn (array $row): array => [$row['id'], array_column($row['signals'], 'file')], $report['rows']), 1, 0);
}

test('path globs match page-path segments case-insensitively: pages/ matches Pages/, settings/ matches Settings/; basename globs keep their case', function () {
    $report = calibrationReport('page-case', calibrationInventory([
        'auth.screens' => [['kind' => 'file-name', 'globs' => ['resources/js/pages/Auth/**/*.vue']]],
        'account.pages' => [['kind' => 'file-name', 'globs' => ['resources/js/pages/settings/*.vue']]],
        'billing.pages' => [['kind' => 'file-name', 'globs' => ['resources/js/pages/Billing/*.vue']]],
        'named.lower' => [['kind' => 'file-name', 'globs' => ['login.vue']]],
    ]));

    expect(calibrationEvidence($report))->toBe([
        // Positive: the Jetstream/Breeze capital-P layout reads like kit's lowercase one.
        'auth.screens' => ['resources/js/Pages/Auth/Login.vue'],
        'account.pages' => ['resources/js/Pages/Settings/Profile.vue'],
        // Negative: no Billing folder, and Components/Auth/Login.vue is not under pages/.
        'billing.pages' => [],
        // Negative: a basename glob is a component name and stays case-sensitive.
        'named.lower' => [],
    ])
        ->and(App::pathGlob('resources/js/pages/**/Login.vue', 'resources/js/Pages/Auth/Login.vue'))->toBeTrue()
        ->and(App::pathGlob('resources/js/pages/**/Login.vue', 'resources/js/components/Login.vue'))->toBeFalse()
        ->and(App::glob('*Button', 'button'))->toBeFalse();
});

test('route analyses read module route files: tey/mod app/Modules/*/routes, app-modules/*/routes and configured routeRoots', function () {
    $facts = new Facts(new App(calibrationBase('modules')));
    $evidence = fn (string $name): ?string => $facts->analysis($name)['evidence'];

    // Positive: every route analysis finds its evidence in a module route file, without a configuration.
    expect($evidence('destructive-routes'))->toBe('app/Modules/Billing/routes/web.php:8')
        ->and($evidence('settings-routes'))->toBe('app/Modules/Billing/routes/web.php:7')
        ->and($evidence('search-route'))->toBe('app-modules/reports/routes/web.php:6')
        ->and($evidence('signed-in-pages'))->toBe('app-modules/reports/routes/web.php:5')
        // Negative: a module's controller is not a route file, so its 'login' string is no auth route.
        ->and($evidence('auth-routes'))->toBeNull()
        ->and((new App(calibrationBase('modules')))->routes())->toBe(['app-modules/reports/routes/web.php', 'app/Modules/Billing/routes/web.php', 'routes/web.php']);

    // A configured routeRoots glob is read too; a module folder outside every route root is not.
    $base = calibrationBase('modules');
    expect((new App($base, calibrationConfiguration($base, ['routeRoots' => ['app/Modules/*/Http']])))->routes())
        ->toBe(['app-modules/reports/routes/web.php', 'app/Modules/Billing/Http/Controllers/InvoiceController.php', 'app/Modules/Billing/routes/web.php', 'routes/web.php'])
        ->and((new Facts(new App(calibrationBase('page-case'))))->analysis('destructive-routes')['value'])->toBeFalse();
});

test('signed-in-pages counts only auth routes that render a page, never routes/api.php or an api group', function () {
    $evidence = fn (string $case): ?string => (new Facts(new App(calibrationBase('signed-in/'.$case))))->analysis('signed-in-pages')['evidence'];

    // Negative: routes/api.php, an `api` middleware group, an `api/...` prefix and an auth route whose controller renders nothing.
    expect($evidence('api-only'))->toBeNull()
        // Positive: an auth group whose controller calls Inertia::render, and a chained ->middleware('auth') on a view() route.
        ->and($evidence('controller'))->toBe('routes/web.php:6')
        ->and($evidence('chained'))->toBe('routes/web.php:5');
});

test('Laravel Mix and webpack-era build tooling is never a library gap; a UI library with a tooling-like name still is', function () {
    $report = calibrationReport('tooling', calibrationInventory(['layout.page' => [['kind' => 'file-name', 'globs' => ['Nothing.vue']]]]));

    // Negative: every devDependency in the fixture is tooling. Positive: chart.js, and vue-loading-overlay, which only resembles
    // `*-loader`.
    expect(array_column($report['gaps'], 'subject'))->toBe(['npm:chart.js', 'npm:vue-loading-overlay'])
        ->and($report['lists'])->toBe(3)
        ->and(Lists::tooling('resolve-url-loader'))->toBeTrue()
        ->and(Lists::tooling('loader'))->toBeFalse()
        ->and(Lists::tooling('@babel/runtime'))->toBeTrue()
        ->and(Lists::tooling('axios'))->toBeFalse();
});

test('Storybook files are excluded from every signal, fact and gap scan: *.stories.*, .storybook/ and a stories/ folder outside pages/', function () {
    $base = calibrationBase('storybook');
    $inventory = calibrationInventory(
        ['workbench' => [['kind' => 'import', 'specifiers' => ['vuedraggable']]], 'layout.header' => [['kind' => 'vue-root', 'tags' => ['header']]]],
        [['id' => 'has-page-actions', 'description' => 'A header holds buttons.', 'computed' => ['signals' => [['kind' => 'vue-root', 'tags' => ['header'], 'requires' => ['components' => ['*Button'], 'min' => 1]]]]]],
    );
    $report = calibrationReport('storybook', $inventory);

    // Negative: the three Storybook forms never reach a signal, a fact or a gap (stories/PageHeader.vue would fire all three).
    expect((new App($base))->frontend())->toBe(['resources/js/components/Button.vue', 'resources/js/pages/stories/Index.vue'])
        ->and($report['facts']['tey/kit']['has-page-actions']['value'])->toBeFalse()
        ->and(calibrationEvidence($report)['layout.header'])->toBe([])
        ->and(array_column($report['gaps'], 'subject'))->toBe(['resources/js/components/Button.vue'])
        // Positive: a domain `pages/stories/` page is the app's own and still counts.
        ->and(calibrationEvidence($report)['workbench'])->toBe(['resources/js/pages/stories/Index.vue'])
        ->and(App::storybook('resources/js/components/Card.stories.vue'))->toBeTrue()
        ->and(App::storybook('resources/js/components/Stories.vue'))->toBeFalse();
});

test('facts that never fired: a kebab-case header button counts for has-page-actions, and a module model counts for has-workspaces', function () {
    $facts = [
        ['id' => 'has-page-actions', 'description' => 'A header holds buttons.', 'computed' => ['signals' => [['kind' => 'vue-root', 'tags' => ['header'], 'requires' => ['components' => ['Button', '*Button', 'Link'], 'min' => 1]]]]],
        ['id' => 'has-workspaces', 'description' => 'A team model.', 'computed' => ['analysis' => 'workspace-model']],
    ];
    $read = fn (string $case): array => calibrationReport('facts/'.$case, calibrationInventory(['layout.page' => [['kind' => 'file-name', 'globs' => ['Nothing.vue']]]], $facts))['facts']['tey/kit'];

    // Positive: <primary-button> is <PrimaryButton>; app-modules/*/src is app code.
    expect($read('positive')['has-page-actions'])->toMatchArray(['value' => true, 'evidence' => 'resources/js/pages/Orders/Header.vue:2 root <header> with <primary-button>'])
        ->and($read('positive')['has-workspaces'])->toMatchArray(['value' => true, 'evidence' => 'app-modules/teams/src/Models/Team.php:7'])
        // Negative: a header with no button, and a TeamInvitation class, are neither.
        ->and($read('negative')['has-page-actions']['value'])->toBeFalse()
        ->and($read('negative')['has-workspaces']['value'])->toBeFalse()
        ->and(Code::pascal('primary-button'))->toBe('PrimaryButton')
        ->and(Code::pascal('header'))->toBe('header');
});

test('route analyses apply a group\'s middleware and prefix to the route files it includes; a non-literal include is a blind spot', function () {
    $facts = fn (string $case): Facts => new Facts(new App(calibrationBase('includes/'.$case)));

    // Positive: an auth group that requires routes/app.php (Inertia::render pages), and `->group(base_path(...))`; a cycle terminates.
    expect($facts('auth')->analysis('signed-in-pages'))->toBe(['value' => true, 'evidence' => 'routes/web.php:6 includes routes/app.php'])
        ->and($facts('auth')->analysis('public-pages')['value'])->toBeFalse()
        ->and($facts('auth')->blindSpots())->toBe([])
        ->and($facts('cycle')->analysis('signed-in-pages')['value'])->toBeTrue()
        // Negative: the same include under `web` only is not signed in (its pages are public), nor under an api group.
        ->and($facts('guest')->analysis('signed-in-pages')['value'])->toBeFalse()
        ->and($facts('guest')->analysis('public-pages'))->toBe(['value' => false, 'evidence' => null])
        ->and($facts('api')->analysis('signed-in-pages')['value'])->toBeFalse();

    // A non-literal include is not guessed at: routes/app.php is not read as signed in, and the include is recorded.
    $dynamic = $facts('dynamic');
    expect($dynamic->analysis('signed-in-pages')['value'])->toBeFalse()
        ->and($dynamic->blindSpots())->toBe(["routes/web.php:6 includes a route file standards cannot resolve (__DIR__.'/'.config('app.area').'.php'); its routes are not read"])
        ->and(calibrationReport('includes/dynamic', calibrationInventory(['layout.page' => [['kind' => 'file-name', 'globs' => ['Nothing.vue']]]]))['warnings'])
        ->toContain("routes/web.php:6 includes a route file standards cannot resolve (__DIR__.'/'.config('app.area').'.php'); its routes are not read");
});

test('non-UI libraries are never gaps, as packages or as a component\'s imports; lodash, date libraries and UI libraries still are', function () {
    $report = calibrationReport('non-ui', calibrationInventory(['layout.page' => [['kind' => 'file-name', 'globs' => ['Nothing.vue']]]]));

    // Negative: every other dependency is on Lists::NON_UI, and ProjectLoader imports only axios and nanoid. Positive: dayjs (date
    // display), lodash and lodash-es (left for review), vue-select, and ProjectPicker through vue-select alone.
    expect(array_column($report['gaps'], 'subject'))->toBe(['resources/js/components/ProjectPicker.vue', 'npm:dayjs', 'npm:lodash', 'npm:lodash-es', 'npm:vue-select'])
        ->and(array_column($report['gaps'], 'detail'))->toContain('app component: imports vue-select')
        ->and($report['lists'])->toBe(3)
        ->and(Lists::nonUi('@inertiajs/vue3'))->toBeTrue()
        ->and(Lists::nonUi('@vueuse/components'))->toBeFalse()
        ->and(Lists::nonUi('axios-mock-adapter'))->toBeFalse();
});

test('the component gap key is case-insensitive: BreadCrumbs.vue and Breadcrumbs.vue are one gap, citing every file', function () {
    $report = calibrationReport('gap-case', calibrationInventory(['layout.page' => [['kind' => 'file-name', 'globs' => ['Nothing.vue']]]]));
    $gaps = array_column($report['gaps'], null, 'subject');

    // Positive: the two spellings merge under the first-seen path (layout/ sorts before nav/). Negative: BreadcrumbList is another
    // name and stays its own gap.
    expect(array_keys($gaps))->toBe(['resources/js/components/layout/BreadcrumbList.vue', 'resources/js/components/layout/Breadcrumbs.vue', 'npm:vue-select'])
        ->and(array_column($gaps['resources/js/components/layout/Breadcrumbs.vue']['files'], 'file'))->toBe(['resources/js/components/layout/Breadcrumbs.vue', 'resources/js/components/nav/BreadCrumbs.vue'])
        ->and(array_column($gaps['resources/js/components/layout/BreadcrumbList.vue']['files'], 'file'))->toBe(['resources/js/components/layout/BreadcrumbList.vue'])
        ->and($report['summary']['gaps'])->toBe(3)
        ->and(array_values(array_filter($report['blocking'], fn (array $item): bool => $item['subject'] === 'resources/js/components/layout/Breadcrumbs.vue'))[0]['evidence'])
        ->toBe([['file' => 'resources/js/components/layout/Breadcrumbs.vue', 'line' => 2], ['file' => 'resources/js/components/nav/BreadCrumbs.vue', 'line' => 2]]);
});
