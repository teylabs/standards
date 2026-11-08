<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Checks\SurfaceOnTintedGround;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;
use Tey\Standards\Enforcement\Gate;

const TINTED_CSS = "@import 'tailwindcss';\n\n:root {\n    --kit-ground: tinted;\n    --background: #f3f5e7;\n    --card: #ffffff;\n}\n";

/** A composer.lock that locks tey/kit at $version. */
function tintedLock(string $version): string
{
    return (string) json_encode(['packages' => [['name' => 'tey/kit', 'version' => $version]], 'packages-dev' => []]);
}

/** A Configuration over a scratch app whose frontend root is resources/js. */
function tintedConfiguration(string $base, array $overrides = []): Configuration
{
    $config = new Configuration(
        base: $base,
        classRoots: [], sourceRoots: [], uiRoots: [], uiNamespaces: [], hostNamespaces: [],
        controllerRoots: [], routeRoots: [], controllerSuffix: 'Controller', routeDirectory: 'routes',
        viewModelRoots: [], viewModelBases: [], typedViewModel: '', viewModelSuffix: 'ViewModel',
        pageRoots: [], pageScaffolds: [], placements: [], retired: [], discoveryRoots: [],
        developmentNamespaces: [], safeAttributes: [], mediaTrait: '', mediaInterface: '',
        frontendRoots: ['resources/js'],
    );

    return new Configuration(...array_replace(get_object_vars($config), $overrides));
}

/** Rule ui.surface-on-tinted-ground on a scratch app with the given files. */
function tintedFindings(array $files, array $options = []): array
{
    $base = dirname(__DIR__).'/.phpunit.cache/tinted-'.bin2hex(random_bytes(5));
    foreach (['resources/js/pages/.keep' => '', ...$files] as $path => $contents) {
        is_dir(dirname($base.'/'.$path)) || mkdir(dirname($base.'/'.$path), 0777, true);
        file_put_contents($base.'/'.$path, $contents);
    }
    try {
        return array_map(fn (array $v): string => $v['target'].':'.$v['line'].' '.$v['check'].(($v['advisory'] ?? false) ? ' [advisory]' : ''), (new Conformance(tintedConfiguration($base, $options)))->violations('ui.surface-on-tinted-ground'));
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
}

$page = <<<'VUE'
<template>
    <Page title="Members">
        <table><tr><td>Ana</td></tr></table>
        <form @submit.prevent><input name="name" /></form>
        <form><FormCard title="Details" /></form>
        <DataTable :table="table" />
        <FormCard title="Profile" />
        <PageGrid preset="halves">
            <PageGridItem><table /></PageGridItem>
            <Card><table /></Card>
        </PageGrid>
        <PageSection title="Notes"><table /></PageSection>
    </Page>
</template>
VUE;

test('ui.surface-on-tinted-ground warns on a raw table or form straight on a tinted ground, and on a DataTable when the kit predates surfaces', function () use ($page) {
    expect(tintedFindings(['resources/css/app.css' => TINTED_CSS, 'composer.lock' => tintedLock('v0.15.20'), 'resources/js/pages/Members.vue' => $page]))->toBe([
        'resources/js/pages/Members.vue:3 a <table> sits straight on the tinted ground in <Page>: put it on a card [advisory]',
        'resources/js/pages/Members.vue:4 a <form> sits straight on the tinted ground in <Page>: use FormCard or put it on a card [advisory]',
        'resources/js/pages/Members.vue:6 a <DataTable> sits straight on the tinted ground in <Page>, and tey/kit 0.15.20 draws no card for it ('.SurfaceOnTintedGround::KIT_SURFACES.'+ does): put it on a card or update the kit [advisory]',
        'resources/js/pages/Members.vue:9 a <table> sits straight on the tinted ground in <PageGridItem>: put it on a card [advisory]',
    ]);
});

test('ui.surface-on-tinted-ground leaves a DataTable alone once the kit draws its card, or when no kit release is locked', function (?string $version) use ($page) {
    $files = ['resources/css/app.css' => TINTED_CSS, 'resources/js/pages/Members.vue' => $page];
    if ($version !== null) {
        $files['composer.lock'] = tintedLock($version);
    }
    expect(array_filter(tintedFindings($files), fn (string $finding): bool => str_contains($finding, 'DataTable')))->toBe([])
        ->and(tintedFindings($files))->toHaveCount(3);
})->with([
    'the release that draws it' => ['v'.SurfaceOnTintedGround::KIT_SURFACES],
    'a later release' => ['1.0.0'],
    'a branch' => ['dev-main'],
    'no lock' => [null],
]);

test('ui.surface-on-tinted-ground does nothing without a tinted ground: none declared, a white one, or only in a comment', function (?string $css) use ($page) {
    $files = ['composer.lock' => tintedLock('0.15.0'), 'resources/js/pages/Members.vue' => $page];
    if ($css !== null) {
        $files['resources/css/app.css'] = $css;
    }
    expect(tintedFindings($files))->toBe([]);
})->with([
    'no stylesheet' => [null],
    'no declaration' => ["@import 'tailwindcss';\n:root { --background: #f3f5e7; }\n"],
    'another value' => [":root { --kit-ground: white; }\n"],
    'a comment' => ["/* :root { --kit-ground: tinted; } */\n:root { --background: #fff; }\n"],
    'a string' => [".x { content: \"--kit-ground: tinted;\"; }\n"],
    'another selector' => [".theme { --kit-ground: tinted; }\n"],
]);

test('ui.surface-on-tinted-ground reads a declaration in any stylesheet under resources/css and skips the kit\'s own files', function () use ($page) {
    expect(tintedFindings([
        'resources/css/theme/carden.css' => ":root{--kit-ground:tinted}\n",
        'resources/js/components/Kit/Layout/Example.vue' => $page,
        'resources/js/pages/Plain.vue' => "<template>\n    <Page>\n        <table />\n    </Page>\n</template>\n",
    ]))->toBe(['resources/js/pages/Plain.vue:3 a <table> sits straight on the tinted ground in <Page>: put it on a card [advisory]']);
});

test('ui.surface-on-tinted-ground reads kebab-case kit tags and looks through a template wrapper in a form', function () {
    expect(tintedFindings([
        'resources/css/app.css' => TINTED_CSS,
        'resources/js/pages/Kebab.vue' => "<template>\n    <page-grid>\n        <page-grid-item>\n            <table />\n        </page-grid-item>\n    </page-grid>\n    <Page>\n        <form><template v-if=\"show\"><FormCard /></template></form>\n    </Page>\n</template>\n",
    ]))->toBe(['resources/js/pages/Kebab.vue:4 a <table> sits straight on the tinted ground in <PageGridItem>: put it on a card [advisory]']);
});

test('ui.surface-on-tinted-ground stays warn only: not an opt-in advisory, and listing it in optionalRules still warns', function () {
    $files = ['resources/css/app.css' => TINTED_CSS, 'resources/js/pages/Plain.vue' => "<template><Page><table /></Page></template>\n"];
    expect(Gate::ADVISORY)->not->toContain('ui.surface-on-tinted-ground')
        ->and(Conformance::TESTS)->toHaveKey('ui.surface-on-tinted-ground')
        ->and(tintedFindings($files, ['optionalRules' => ['ui.surface-on-tinted-ground']]))->toHaveCount(1)->each->toEndWith('[advisory]');
});
