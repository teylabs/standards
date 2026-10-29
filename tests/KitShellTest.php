<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\AssertionFailedError;
use Tey\Standards\Checks\KitShell;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;
use Tey\Standards\Ratchet;

function kitShellConfiguration(string $base, array $allowlist = []): Configuration
{
    return new Configuration(
        base: str_starts_with($base, '/') ? $base : dirname(__DIR__).'/fixtures/KitShell/'.$base,
        classRoots: ['src' => 'FixtureKitShell'],
        sourceRoots: ['src'],
        uiRoots: ['src/UI'],
        uiNamespaces: ['FixtureKitShell\\UI'],
        hostNamespaces: ['FixtureKitShell'],
        controllerRoots: ['src/Http/Controllers'],
        routeRoots: ['routes'],
        controllerSuffix: 'Controller',
        routeDirectory: 'routes',
        viewModelRoots: ['src/UI'],
        viewModelBases: [],
        typedViewModel: '',
        viewModelSuffix: 'ViewModel',
        pageRoots: ['resources/js/pages'],
        pageScaffolds: [],
        placements: [],
        retired: [],
        discoveryRoots: ['src'],
        developmentNamespaces: ['Pest'],
        safeAttributes: [],
        mediaTrait: 'FixtureKitShell\\MediaBehaviour',
        mediaInterface: 'FixtureKitShell\\MediaContract',
        allowlist: $allowlist,
    );
}

/** @return list<array{target: string, check: string, line?: int}> without hints */
function kitShellFindings(string $case): array
{
    return array_map(fn (array $finding): array => array_diff_key($finding, ['hint' => true]), (new KitShell)->violations(kitShellConfiguration($case)));
}

const KIT_SHELL_HEADER = "signed-in <header> outside kit's shell";

test('[ui.kit-shell] app.ts resolving signed-in pages to an app-owned layout fails; the guest branch does not', function () {
    expect(kitShellFindings('app-layout'))->toBe([
        ['target' => 'resources/js/app.ts', 'check' => "signed-in pages resolve to an app-owned layout (resources/js/layouts/ManagementLayout.vue), not kit's Workspace Layout", 'line' => 12],
    ]);
});

test('[ui.kit-shell] a persistent layout default in resolve, and a layout returned by a local function, are read too', function () {
    expect(kitShellFindings('persistent-default'))->toBe([
        ['target' => 'resources/js/app.ts', 'check' => "signed-in pages resolve to an app-owned layout (resources/js/layouts/AppShell.vue), not kit's Workspace Layout", 'line' => 7],
    ])->and(kitShellFindings('local-function'))->toBe([
        ['target' => 'resources/js/app.ts', 'check' => "signed-in pages resolve to an app-owned layout (resources/js/layouts/Portal.vue), not kit's Workspace Layout", 'line' => 10],
    ]);
});

test('[ui.kit-shell] a resolver too dynamic to read is not flagged', function () {
    expect(kitShellFindings('dynamic'))->toBe([]);
});

test('[ui.kit-shell] a host header with an account menu and a sign-out fails, once, as does a nav with a workspace switcher; an account menu alone does not', function () {
    expect(kitShellFindings('host-header'))->toBe([
        ['target' => 'resources/js/components/ManagementHeader.vue', 'check' => KIT_SHELL_HEADER, 'line' => 6],
        ['target' => 'resources/js/layouts/TopBar.vue', 'check' => "signed-in <nav> outside kit's shell", 'line' => 4],
    ]);
});

test('[ui.kit-shell] a starter-kit header whose account menu is a Menu-prefixed component (UserMenuContent, user-menu-content) fails; MenuButton and NavigationMenu links do not', function () {
    expect(kitShellFindings('starter-header'))->toBe([
        ['target' => 'resources/js/components/AppHeader.vue', 'check' => KIT_SHELL_HEADER, 'line' => 10],
        ['target' => 'resources/js/components/TopNav.vue', 'check' => "signed-in <nav> outside kit's shell", 'line' => 2],
    ]);
});

test('[ui.kit-shell] PublicShell, GuestShell, marketing headers and public-named layouts pass', function () {
    expect(kitShellFindings('public'))->toBe([]);
});

test("[ui.kit-shell] kit's Layout passes: imported directly, as `Layout` from @kit/shell, or through an app wrapper; an array's sublayout is not the shell", function () {
    expect(kitShellFindings('kit-layout'))->toBe([]);
});

test('[ui.kit-shell] page-local sub-navs and tabs pass', function () {
    expect(kitShellFindings('tab-subnav'))->toBe([]);
});

test('[ui.kit-shell] a host that has not adopted the shell is inactive; a literal kit.features list overrides the lineages', function () {
    $base = dirname(__DIR__).'/fixtures/KitShell/';

    expect(kitShellFindings('inactive'))->toBe([])
        ->and(KitShell::adopted($base.'inactive'))->toBeFalse()
        ->and(kitShellFindings('override'))->toBe([])
        ->and(KitShell::adopted($base.'override'))->toBeFalse()
        ->and(KitShell::adopted($base.'lineage'))->toBeTrue()
        ->and(kitShellFindings('lineage'))->toBe([['target' => 'resources/js/components/ManagementHeader.vue', 'check' => KIT_SHELL_HEADER, 'line' => 6]])
        ->and(KitShell::adopted($base.'host-header'))->toBeTrue();
});

test('[ui.kit-shell] kit-owned files are skipped: the Kit tree and an unedited file tey/mod records', function () {
    expect(kitShellFindings('kit-owned'))->toBe([]);
});

test('[ui.kit-shell] an edited kit-generated file is checked again', function () {
    expect(kitShellFindings('kit-edited'))->toBe([
        ['target' => 'resources/js/pages/Workspace/Layout.vue', 'check' => KIT_SHELL_HEADER, 'line' => 6],
    ]);
});

test('[ui.kit-shell] an allowlist entry needs a reason and an expiry; a complete one permits exactly its finding', function () {
    $entry = ['rule' => KitShell::RULE, 'target' => 'resources/js/components/ManagementHeader.vue', 'check' => KIT_SHELL_HEADER, 'date' => '2026-10-29'];

    expect(fn () => new Ratchet([$entry]))->toThrow(InvalidArgumentException::class, 'non-empty [reason]')
        ->and(fn () => new Ratchet([[...$entry, 'reason' => ' ', 'expires' => '2099-12-31']]))->toThrow(InvalidArgumentException::class, 'non-empty [reason]')
        ->and(fn () => (new Conformance(kitShellConfiguration('lineage', [[...$entry, 'reason' => 'moving to the kit shell']])))->assert(KitShell::RULE))
        ->toThrow(AssertionFailedError::class, 'exception has no expiry');

    (new Conformance(kitShellConfiguration('lineage', [[...$entry, 'reason' => 'moving to the kit shell', 'expires' => '2099-12-31']])))->assert(KitShell::RULE);
    expect(fn () => (new Conformance(kitShellConfiguration('host-header', [[...$entry, 'reason' => 'moving to the kit shell', 'expires' => '2099-12-31']])))->assert(KitShell::RULE))
        ->toThrow(AssertionFailedError::class, 'violation: resources/js/layouts/TopBar.vue');
});

test('[ui.kit-shell] the rule is registered as a required conformance rule', function () {
    expect(Conformance::TESTS)->toHaveKey(KitShell::RULE)
        ->and(Tey\Standards\Enforcement\Gate::OPTIONAL)->not->toContain(KitShell::RULE);
});

test('[ui.kit-shell] the package generators and the Good fixture emit no signed-in header the rule rejects', function () {
    $checked = 0;

    foreach ([dirname(__DIR__).'/resources/stubs', dirname(__DIR__).'/fixtures/Good'] as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_contains($file->getFilename(), '.vue')) {
                $checked++;
                expect(KitShell::header((string) file_get_contents($file->getPathname())))->toBe([], $file->getPathname());
            }
        }
    }

    expect($checked)->toBeGreaterThan(1)
        ->and((new KitShell)->violations(kitShellConfiguration(dirname(__DIR__).'/fixtures/Good')))->toBe([]);
});

test('[ui.kit-shell] fails closed: an app.ts it cannot read, or a .vue file it cannot read, is a finding, not a pass', function (string $path, string $contents) {
    $base = sys_get_temp_dir().'/kit-shell-'.bin2hex(random_bytes(6));
    mkdir($base.'/config', 0777, true);
    mkdir(dirname($base.'/'.$path), 0777, true);
    file_put_contents($base.'/config/kit.php', "<?php\n\nreturn ['features' => ['core', 'shell']];\n");
    file_put_contents($base.'/'.$path, $contents);
    chmod($base.'/'.$path, 0000);

    try {
        if (is_readable($base.'/'.$path)) {
            $this->markTestSkipped('Running as a user that reads mode-0000 files (root); unreadable files cannot be simulated.');
        }
        expect(kitShellFindings($base))->toBe([['target' => $path, 'check' => 'could not read the file', 'line' => 1]]);
    } finally {
        chmod($base.'/'.$path, 0644);
        (new Filesystem)->deleteDirectory($base);
    }
})->with([
    'the layout entry' => ['resources/js/app.ts', "import { createInertiaApp } from '@inertiajs/vue3';\n"],
    'a .vue file' => ['resources/js/components/AppHeader.vue', "<template><header><UserMenu /></header></template>\n"],
]);
