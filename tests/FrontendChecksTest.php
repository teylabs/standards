<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedWithMessageException;
use Tey\Standards\Checks\FrontendSource;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;

function frontendConfiguration(string $kind = 'Bad', array $overrides = []): Configuration
{
    $config = new Configuration(
        base: dirname(__DIR__).'/fixtures/'.$kind,
        classRoots: [], sourceRoots: ['routes'], uiRoots: [], uiNamespaces: [], hostNamespaces: [],
        controllerRoots: [], routeRoots: [], controllerSuffix: 'Controller', routeDirectory: 'routes',
        viewModelRoots: [], viewModelBases: [], typedViewModel: '', viewModelSuffix: 'ViewModel',
        pageRoots: [], pageScaffolds: [], placements: [], retired: [], discoveryRoots: [],
        developmentNamespaces: [], safeAttributes: [], mediaTrait: '', mediaInterface: '',
        optionalRules: ['ui plus', 'ui new-tab', 'ui dropzone', 'ui toast'],
    );

    return new Configuration(...array_replace(get_object_vars($config), $overrides));
}

test('frontend import scan includes static, side-effect, dynamic, re-export and type-only imports', function () {
    expect(array_column((new Conformance(frontendConfiguration()))->violations('js-imports'), 'check'))->toEqualCanonicalizing([
        'undeclared package import: unlisted-side-effect',
        'undeclared package import: @missing/types',
        'undeclared package import: unlisted-export/subpath',
        'undeclared package import: unlisted-dynamic',
        'undeclared package import: vue-sonner',
    ]);
    expect((new Conformance(frontendConfiguration('Good')))->violations('js-imports'))->toBe([]);
});

test('frontend import lexer ignores comments, strings and property import methods', function () {
    expect(FrontendSource::imports(<<<'JS'
        // import 'comment';
        /* export { value } from 'comment'; */
        const example = "import('text')";
        const template = `import('template')`;
        object.import('method');
        import 'side-effect';
        import { from as value } from 'keyword-named-export';
        import { type Type, value } from '@scope/package/deep';
        export * from 'exported';
        const load = () => import('dynamic');
        const templateLoad = () => import(`literal-template`);
        const variableLoad = () => import(`variable-${name}`);
        JS))->toBe(['side-effect', 'keyword-named-export', '@scope/package/deep', 'exported', 'dynamic', 'literal-template']);
});

test('frontend descriptors inspect script, template expressions and PHP arrays', function () {
    expect((new Conformance(frontendConfiguration()))->violations('descriptors'))->toContain(
        ['target' => 'resources/js/Posts.vue', 'check' => 'role or permission check'],
        ['target' => 'resources/js/Posts.vue', 'check' => 'boolean can-map'],
        ['target' => 'routes/responses.php', 'check' => 'response can-map'],
    );
});

test('frontend optional rules skip by default and enforce only selected keys', function () {
    foreach (['ui plus', 'ui new-tab', 'ui dropzone', 'ui toast'] as $rule) {
        $disabled = new Conformance(frontendConfiguration(overrides: ['optionalRules' => []]));
        expect($disabled->violations($rule))->toBe([]);
        expect(fn () => $disabled->assert($rule))->toThrow(SkippedWithMessageException::class, 'disabled');
        expect(fn () => (new Conformance(frontendConfiguration(overrides: ['optionalRules' => [$rule]])))->assert($rule))->toThrow(AssertionFailedError::class, 'violation:');
    }
});

test('frontend findings use exact ratchet entries and reject fixed debt', function () {
    foreach (['descriptors', 'js-imports', 'ui plus', 'ui new-tab', 'ui dropzone', 'ui toast'] as $rule) {
        $violations = (new Conformance(frontendConfiguration()))->violations($rule);
        $allowlist = array_map(fn (array $entry): array => ['rule' => $rule, ...$entry, 'reason' => 'Fixture debt', 'date' => '2026-10-24'], $violations);
        (new Conformance(frontendConfiguration(overrides: ['allowlist' => $allowlist])))->assert($rule);
        expect(fn () => (new Conformance(frontendConfiguration('Good', ['allowlist' => $allowlist])))->assert($rule))->toThrow(AssertionFailedError::class, 'stale exception');
        array_pop($allowlist);
        expect(fn () => (new Conformance(frontendConfiguration(overrides: ['allowlist' => $allowlist])))->assert($rule))->toThrow(AssertionFailedError::class, 'violation:');
    }
});

test('frontend roots, aliases, components, icons and owners are configurable', function () {
    $base = dirname(__DIR__).'/.phpunit.cache/frontend-'.bin2hex(random_bytes(5));
    mkdir($base.'/client', 0777, true);
    file_put_contents($base.'/package.json', '{}');
    file_put_contents($base.'/client/Posts.vue', <<<'VUE'
        <script setup>
        import Widget from '@kit/ui';
        import { toast } from 'custom-toast';
        </script>
        <template>
            <Action><AddIcon />Create post</Action>
            <Action target="_blank" rel="noopener noreferrer"><DiagonalIcon />Posts</Action>
            <input type="file">
            <p>{{ flash.success }}</p>
        </template>
        VUE);
    $config = frontendConfiguration(overrides: [
        'base' => $base, 'frontendRoots' => ['client'], 'frontendAliases' => ['@kit/'],
        'descriptorResponseRoots' => [], 'uiActionComponents' => ['Action'],
        'uiPlusIcons' => ['AddIcon'], 'uiExternalIcons' => ['DiagonalIcon'],
        'uiDropzone' => 'client/Posts.vue', 'uiToastLibrary' => 'custom-toast',
        'uiToastModule' => 'client/Posts.vue', 'uiNotificationCenter' => 'client/Posts.vue',
    ]);
    try {
        foreach (['ui plus', 'ui new-tab', 'ui dropzone', 'ui toast'] as $rule) {
            (new Conformance($config))->assert($rule);
        }
        expect((new Conformance($config))->violations('js-imports'))->toBe([
            ['target' => 'client/Posts.vue', 'check' => 'undeclared package import: custom-toast'],
        ]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('frontend descriptor patterns catch common authorization spellings without matching prose', function (string $source, bool $violation) {
    $base = dirname(__DIR__).'/.phpunit.cache/descriptors-'.bin2hex(random_bytes(5));
    mkdir($base.'/client', 0777, true);
    file_put_contents($base.'/client/Posts.vue', $source);
    try {
        $preset = new Conformance(frontendConfiguration(overrides: ['base' => $base, 'frontendRoots' => ['client'], 'descriptorResponseRoots' => []]));
        expect($preset->violations('descriptors') !== [])->toBe($violation);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
})->with([
    'roles property' => ['<script>const owner = user.roles;</script>', true],
    'role equality' => ["<script>const owner = role === 'owner';</script>", true],
    'permission includes' => ["<script>permissions.includes('edit');</script>", true],
    'permission helper' => ["<script>hasPermission('edit');</script>", true],
    'admin flag' => ['<script>const owner = props.isAdmin;</script>', true],
    'can property' => ['<script>props.can.update;</script>', true],
    'can map' => ['<script>defineProps({ can: { update: Boolean } });</script>', true],
    'template condition' => ['<template><button v-if="user.roles">Edit</button></template>', true],
    'descriptor condition' => ['<template><button v-if="actions.update">Edit</button></template>', false],
    'comment and prose' => ["<script>// hasRole();\nconst text = 'hasRole, isAdmin and can.update';</script><template><p>isAdmin</p></template>", false],
    'local colour map' => ['<template><Badge :variant="roles[state].variant" /></template>', false],
]);

test('frontend static UI checks cover self-closing actions, rel tokens, raw inputs and flash text', function (string $rule, string $source, bool $violation) {
    $base = dirname(__DIR__).'/.phpunit.cache/ui-'.bin2hex(random_bytes(5));
    mkdir($base.'/client', 0777, true);
    file_put_contents($base.'/client/Posts.vue', $source);
    try {
        $preset = new Conformance(frontendConfiguration(overrides: ['base' => $base, 'frontendRoots' => ['client']]));
        expect($preset->violations($rule) !== [])->toBe($violation);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
})->with([
    'self-closing add' => ['ui plus', '<template><Button label="Add post" /></template>', true],
    'self-closing add icon' => ['ui plus', '<template><Button label="Add post" icon="Plus" /></template>', false],
    'new label' => ['ui plus', '<template><button>New order</button></template>', true],
    'ordinary label' => ['ui plus', '<template><button>Recreate order</button></template>', false],
    'new tab rel token' => ['ui new-tab', '<template><a target="_blank" rel="noreferrer noopener"><ExternalLink />Posts</a></template>', false],
    'new tab misleading rel' => ['ui new-tab', '<template><a target="_blank" rel="noopener-wrong"><ArrowUpRight />Posts</a></template>', true],
    'new tab missing icon' => ['ui new-tab', '<template><a target="_blank" rel="noopener">Posts</a></template>', true],
    'ordinary link' => ['ui new-tab', '<template><a>Posts</a></template>', false],
    'raw file input' => ['ui dropzone', "<template><input type='file'></template>", true],
    'literal bound file input' => ['ui dropzone', '<template><input :type="\'file\'"></template>', true],
    'ordinary input' => ['ui dropzone', '<template><input type="text"></template>', false],
    'toast import' => ['ui toast', "<script>import 'vue-sonner';</script>", true],
    'loose flash' => ['ui toast', '<template><p>{{ page.props.flash?.success }}</p></template>', true],
    'commented flash' => ['ui toast', '<template><!-- {{ flash.success }} --></template>', false],
]);

test('frontend declarations and explicit missing roots are checked', function () {
    $base = dirname(__DIR__).'/.phpunit.cache/imports-'.bin2hex(random_bytes(5));
    mkdir($base.'/client', 0777, true);
    file_put_contents($base.'/client/types.d.ts', "import type { Missing } from 'missing-types';");
    file_put_contents($base.'/package.json', '{}');
    try {
        $preset = new Conformance(frontendConfiguration(overrides: ['base' => $base, 'frontendRoots' => ['client']]));
        expect($preset->violations('js-imports'))->toBe([['target' => 'client/types.d.ts', 'check' => 'undeclared package import: missing-types']]);
        // A frontend root that does not exist yet has nothing to check (Gate reports it once as configuration).
        expect((new Conformance(frontendConfiguration(overrides: ['base' => $base, 'frontendRoots' => ['missing']])))->violations('js-imports'))->toBe([]);
        unlink($base.'/package.json');
        expect($preset->violations('js-imports'))->toBe([['target' => 'package.json', 'check' => 'missing package manifest for frontend imports']]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('frontend Vue script extraction ignores commented scripts', function () {
    expect(FrontendSource::imports(FrontendSource::script('Posts.vue', "<!-- <script>import 'comment';</script> --><script>import 'real';</script>")))->toBe(['real']);
});

test('ui new-tab counts a configured label component as the external icon', function () {
    $base = dirname(__DIR__).'/.phpunit.cache/label-'.bin2hex(random_bytes(5));
    mkdir($base.'/client', 0777, true);
    file_put_contents($base.'/client/Posts.vue', '<template><a target="_blank" rel="noopener"><ExternalLabel>Posts</ExternalLabel></a></template>');
    try {
        expect((new Conformance(frontendConfiguration(overrides: ['base' => $base, 'frontendRoots' => ['client']])))->violations('ui new-tab'))->toHaveCount(1);
        $config = frontendConfiguration(overrides: ['base' => $base, 'frontendRoots' => ['client'], 'uiExternalLabelComponents' => ['ExternalLabel']]);
        expect((new Conformance($config))->violations('ui new-tab'))->toBe([]);
        file_put_contents($base.'/client/Posts.vue', '<template><a target="_blank" rel="noopener"><OtherLabel>Posts</OtherLabel></a></template>');
        expect((new Conformance($config))->violations('ui new-tab'))->toHaveCount(1);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('ui toast lets configured renderers import the toast library and nobody else', function () {
    $base = dirname(__DIR__).'/.phpunit.cache/toast-'.bin2hex(random_bytes(5));
    mkdir($base.'/client', 0777, true);
    file_put_contents($base.'/client/Toaster.vue', "<script setup>import { Toaster } from 'vue-sonner';</script>");
    file_put_contents($base.'/client/Caller.vue', "<script setup>import { toast } from 'vue-sonner';</script>");
    try {
        $overrides = ['base' => $base, 'frontendRoots' => ['client']];
        expect(array_column((new Conformance(frontendConfiguration(overrides: $overrides)))->violations('ui toast'), 'target'))->toEqualCanonicalizing(['client/Toaster.vue', 'client/Caller.vue']);
        $config = frontendConfiguration(overrides: [...$overrides, 'uiToastRenderers' => ['client/Toaster.vue']]);
        expect((new Conformance($config))->violations('ui toast'))->toBe([['target' => 'client/Caller.vue', 'check' => 'toast library imported outside configured module']]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('js-imports reads static vite resolve.alias keys and ignores dynamic ones', function (string $config) {
    $base = dirname(__DIR__).'/.phpunit.cache/vite-'.bin2hex(random_bytes(5));
    mkdir($base.'/client', 0777, true);
    file_put_contents($base.'/package.json', '{}');
    file_put_contents($base.'/vite.config.ts', $config);
    file_put_contents($base.'/client/Posts.ts', "import a from '@app/a';\nimport b from '#shared';\nimport c from '@dynamic/c';\nimport d from 'real-missing';\nimport e from '@appx/e';");
    try {
        $overrides = ['base' => $base, 'frontendRoots' => ['client'], 'frontendAliases' => ['@/']];
        expect(array_column((new Conformance(frontendConfiguration(overrides: $overrides)))->violations('js-imports'), 'check'))->toBe([
            'undeclared package import: @dynamic/c',
            'undeclared package import: real-missing',
            'undeclared package import: @appx/e',
        ]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
})->with([
    'object keys' => ["export default defineConfig({ resolve: { alias: { '@app': path.resolve('x'), '#shared': 'y', [dynamicKey]: 'z', ...spread } } });"],
    'find array' => ["export default { resolve: { alias: [{ find: '@app', replacement: 'x' }, { find: \"#shared\", replacement: 'y' }, { find: /^re/, replacement: 'z' }, { find: dynamicFind, replacement: 'w' }] } };"],
]);
