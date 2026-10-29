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
        $allowlist = array_map(fn (array $entry): array => ['rule' => $rule, ...$entry, 'reason' => 'Fixture debt', 'date' => '2026-10-24', 'expires' => '2099-12-31'], $violations);
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
    'flash shown through kit FormStatus' => ['ui toast', "<script setup>import { FormStatus } from '@/components/Kit';</script><template><FormStatus :message=\"\$page.props.flash.status\" /></template>", false],
    'flash on a FormStatus not imported from kit' => ['ui toast', "<script setup>import FormStatus from '@/components/FormStatus.vue';</script><template><FormStatus :message=\"flash.status\" /></template>", true],
    'flash beside a kit FormStatus' => ['ui toast', "<script setup>import { FormStatus } from '@/components/Kit';</script><template><FormStatus /><p>{{ flash.status }}</p></template>", true],
    'v3 $page.flash shown through kit FormStatus' => ['ui toast', "<script setup>import { FormStatus } from '@/components/Kit';</script><template><FormStatus :message=\"\$page?.flash?.status\" /></template>", false],
    'v3 usePage().flash shown through kit FormStatus' => ['ui toast', "<script setup>import { FormStatus } from '@/components/Kit';</script><template><FormStatus :message=\"usePage().flash.success\" /></template>", false],
    'v3 page.flash shown through kit FormStatus' => ['ui toast', "<script setup>import { usePage } from '@inertiajs/vue3'; import { FormStatus } from '@/components/Kit'; const page = usePage();</script><template><FormStatus :message=\"page.flash.message\" /></template>", false],
    'kit FormStatus flash-key names an outcome key' => ['ui toast', "<script setup>import { FormStatus } from '@/components/Kit';</script><template><FormStatus flash-key=\"status\" /><FormStatus :flash-key=\"'success'\" /></template>", false],
    'v3 page.flash beside a kit FormStatus' => ['ui toast', "<script setup>import { FormStatus } from '@/components/Kit'; const page = usePage();</script><template><FormStatus flash-key=\"status\" /><p>{{ page.flash.status }}</p></template>", true],
    'v3 $page.flash on a FormStatus not imported from kit' => ['ui toast', "<script setup>import FormStatus from '@/components/FormStatus.vue';</script><template><FormStatus :message=\"\$page.flash.error\" /></template>", true],
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

/** The `descriptors` findings for one frontend file in a scratch app. */
function descriptorFindings(string $file, string $code): array
{
    $base = sys_get_temp_dir().'/standards-descriptors-'.bin2hex(random_bytes(6));
    mkdir($base.'/resources/js', 0777, true);
    mkdir($base.'/routes');
    file_put_contents($base.'/resources/js/'.$file, $code);

    try {
        return array_column((new Conformance(frontendConfiguration('Bad', ['base' => $base])))->violations('descriptors'), 'check');
    } finally {
        (new Illuminate\Filesystem\Filesystem)->deleteDirectory($base);
    }
}

test('descriptors still catches every auth-shaped check', function (string $file, string $code, string $check) {
    expect(descriptorFindings($file, $code))->toContain($check);
})->with([
    'roles.includes' => ['x.ts', "const ok = user.roles.includes('owner');", 'role or permission check'],
    'hasRole(' => ['x.ts', "if (hasRole('admin')) {}", 'role or permission check'],
    'hasPermission(' => ['x.ts', "hasPermission('posts.edit')", 'role or permission check'],
    'can(' => ['x.ts', "if (can('update')) {}", 'role or permission check'],
    'cannot(' => ['x.ts', "if (cannot('update')) {}", 'role or permission check'],
    '$can(' => ['x.vue', "<script setup></script>\n<template><button v-if=\"\$can('update')\"/></template>", 'role or permission check'],
    'user.can(' => ['x.ts', "if (user.can('update')) {}", 'role or permission check'],
    'auth user ?.can(' => ['x.ts', "if (auth.user?.can('delete')) {}", 'role or permission check'],
    '$page.props.auth.can(' => ['x.vue', "<script setup>\nconst ok = \$page.props.auth.can('update');\n</script>\n<template><div/></template>", 'role or permission check'],
    'page.props.auth.cannot(' => ['x.ts', "const blocked = page.props.auth.cannot('update');", 'role or permission check'],
    'permissions.can(' => ['x.ts', "permissions.can('posts.edit')", 'role or permission check'],
    'gate.can(' => ['x.ts', "gate.can('update', post)", 'role or permission check'],
    'policy.cannot(' => ['x.ts', "policy.cannot('delete')", 'role or permission check'],
    'permissions lookup' => ['x.ts', "const ok = user.permissions.includes('posts.edit');", 'role or permission check'],
    'permissions[' => ['x.ts', "const ok = permissions['posts.edit'];", 'role or permission check'],
    '$page.props.auth roles' => ['x.vue', "<script setup>\nconst r = \$page.props.auth.user.roles;\n</script>\n<template><div/></template>", 'role or permission check'],
    'roles in a template expression' => ['x.vue', "<script setup></script>\n<template><div v-if=\"user.roles.includes('admin')\"/></template>", 'role or permission check'],
    'role compared to a role name' => ['x.ts', "if (user.role === 'admin') {}", 'role or permission check'],
    'a role name compared to a role' => ['x.ts', "if ('owner' !== user.role) {}", 'role or permission check'],
    'is_admin' => ['x.ts', "if (user.is_admin) {}", 'role or permission check'],
    'isAdmin' => ['x.ts', "if (user.isAdmin) {}", 'role or permission check'],
    'boolean can-map' => ['x.ts', 'const a = props.can.update;', 'boolean can-map'],
]);

test('descriptors ignores ARIA roles, domain roles, identifiers that merely contain "role", and can() on other objects', function (string $file, string $code) {
    expect(descriptorFindings($file, $code))->toBe([]);
})->with([
    'an ARIA role attribute' => ['x.vue', "<script setup></script>\n<template><div role=\"group\" :role=\"'group'\"/></template>"],
    'an ARIA role in an object literal' => ['x.ts', "const config = { role: 'textbox', ariaLabel: 'Body' };\nif (el.role === 'textbox') {}"],
    'a domain Role type' => ['x.ts', "type FeedRole = 'actor' | 'object' | 'target';\nconst role: FeedRole = 'actor';\nif (role === 'actor') {}"],
    'a node[role] index' => ['x.ts', "const label = node[role];\nconst kind = item.role === 'object' ? 1 : 2;"],
    'identifiers containing role' => ['x.ts', "const controller = makeController();\nconst payroleCount = 2;\nconst rolesOfSpeech = ['a'];"],
    'Tiptap editor.can().undo()' => ['x.ts', "const undoable = editor.can().undo();\nconst redoable = editor.can().redo();"],
    'a command chain .can()' => ['x.ts', "const ok = editor.chain().focus().can().toggleBold().run();\nconst c = chain.can();"],
    'can() on a Vue ref' => ['x.vue', "<script setup>\nconst ok = editorRef.value?.can().undo();\n</script>\n<template><button :disabled=\"!editor.can().redo()\"/></template>"],
]);

test('js-imports hints the parent\'s range for an undeclared package another declared one depends on (@inertiajs/core under @inertiajs/vue3)', function () {
    $base = dirname(__DIR__).'/.phpunit.cache/inertia-'.bin2hex(random_bytes(5));
    mkdir($base.'/client', 0777, true);
    mkdir($base.'/node_modules/@inertiajs/vue3', 0777, true);
    file_put_contents($base.'/package.json', json_encode(['dependencies' => ['@inertiajs/vue3' => '^3.0.0']]));
    file_put_contents($base.'/node_modules/@inertiajs/vue3/package.json', json_encode(['name' => '@inertiajs/vue3', 'dependencies' => ['@inertiajs/core' => '3.6.1']]));
    file_put_contents($base.'/client/Link.ts', "import type { Method } from '@inertiajs/core';\nimport x from 'left-pad';");

    try {
        $findings = (new Conformance(frontendConfiguration(overrides: ['base' => $base, 'frontendRoots' => ['client']])))->violations('js-imports');

        expect(array_column($findings, 'check'))->toBe(['undeclared package import: @inertiajs/core', 'undeclared package import: left-pad'])
            ->and($findings[0]['hint'])->toContain('@inertiajs/vue3, which depends on @inertiajs/core@3.6.1', '"@inertiajs/core": "3.6.1"', '`overrides`', '`npm ls @inertiajs/core`')
            ->and($findings[1])->not->toHaveKey('hint');
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('ui new-tab passes the kit RowActions shape and kit components that add rel themselves', function () {
    // The kit's own tree (fixtures/KitRowActions mirrors tey/kit's Table/RowActions) is not scanned; a page's kit Button needs no rel of its own.
    expect((new Conformance(frontendConfiguration('KitRowActions', ['frontendRoots' => ['resources/js']])))->violations('ui new-tab'))->toBe([]);
});

test('ui new-tab treats only kit-imported components as adding rel', function (string $source, bool $violation) {
    $base = dirname(__DIR__).'/.phpunit.cache/kit-'.bin2hex(random_bytes(5));
    mkdir($base.'/client', 0777, true);
    file_put_contents($base.'/client/Posts.vue', $source);
    try {
        expect((new Conformance(frontendConfiguration(overrides: ['base' => $base, 'frontendRoots' => ['client']])))->violations('ui new-tab') !== [])->toBe($violation);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
})->with([
    'kit Button default import' => ["<script setup>import Button from '@/components/Kit/Form/Button.vue';</script><template><Button href=\"/x\" external target=\"_blank\"><ExternalLink />Posts</Button></template>", false],
    'kit Button barrel import' => ["<script setup>import { Button } from '@/components/Kit';</script><template><Button target=\"_blank\"><ArrowUpRight />Posts</Button></template>", false],
    'kit alias renamed import' => ["<script setup>import { Button as KitButton } from '@kit/ui';</script><template><Button target=\"_blank\"><ArrowUpRight />Posts</Button></template>", true],
    'kit Button still needs the icon' => ["<script setup>import { Button } from '@/components/Kit';</script><template><Button target=\"_blank\">Posts</Button></template>", true],
    'plain anchor without rel' => ["<script setup>import { Button } from '@/components/Kit';</script><template><a target=\"_blank\"><ExternalLink />Posts</a></template>", true],
    'non-kit Button without rel' => ["<script setup>import { Button } from '@/components/ui/button';</script><template><Button target=\"_blank\"><ExternalLink />Posts</Button></template>", true],
    'type-only kit import' => ["<script setup>import type { Button } from '@/components/Kit';</script><template><Button target=\"_blank\"><ExternalLink />Posts</Button></template>", true],
]);

test('ui new-tab still checks a plain anchor beside the kit tree', function () {
    $base = dirname(__DIR__).'/.phpunit.cache/kit-tree-'.bin2hex(random_bytes(5));
    mkdir($base.'/resources/js/components/Kit/Table', 0777, true);
    mkdir($base.'/resources/js/pages', 0777, true);
    file_put_contents($base.'/resources/js/components/Kit/Table/RowActions.vue', '<template><a target="_blank">Posts</a></template>');
    file_put_contents($base.'/resources/js/pages/Posts.vue', '<template><a target="_blank">Posts</a></template>');
    try {
        expect((new Conformance(frontendConfiguration(overrides: ['base' => $base, 'frontendRoots' => ['resources/js']])))->violations('ui new-tab'))
            ->toBe([['target' => 'resources/js/pages/Posts.vue', 'check' => 'new-tab action needs noopener and external icon']]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

/** A scratch app with the given files under its base; the frontend roots are resources/js. */
function kitOwnershipApp(array $files): string
{
    $base = dirname(__DIR__).'/.phpunit.cache/kit-owned-'.bin2hex(random_bytes(5));
    foreach ($files as $path => $contents) {
        is_dir(dirname($base.'/'.$path)) || mkdir(dirname($base.'/'.$path), 0777, true);
        file_put_contents($base.'/'.$path, $contents);
    }

    return $base;
}

/** tey/mod's installed lineage record shape: format 1, owner tey/mod:installed, artifact.files path => base64 bytes. */
function kitInstalledRecord(array $files, string $owner = 'tey/mod:installed'): string
{
    return (string) json_encode(['format' => 1, 'owner' => $owner, 'app_id' => 'scratch', 'root' => '/app', 'artifact' => [
        'name' => 'tey/kit', 'version' => '0.4.20', 'files' => array_map(base64_encode(...), $files), 'groups' => [], 'checks' => [], 'host_owned' => [],
    ]]);
}

dataset('kit-owned violations', [
    'ui plus' => ['ui plus', '<template><Button>Add post</Button></template>'],
    'ui new-tab' => ['ui new-tab', '<template><a target="_blank">Posts</a></template>'],
    'ui dropzone' => ['ui dropzone', '<template><input type="file"></template>'],
    'ui toast' => ['ui toast', '<template><p>{{ flash.success }}</p></template>'],
    'descriptors' => ['descriptors', "<script setup>const admin = hasRole('admin');</script><template><p /></template>"],
]);

test('the frontend rules skip the kit tree and still check the same code beside it', function (string $rule, string $source) {
    $base = kitOwnershipApp(['resources/js/components/Kit/Form/FileInput.vue' => $source, 'resources/js/pages/Posts.vue' => $source]);
    try {
        $targets = array_column((new Conformance(frontendConfiguration(overrides: ['base' => $base, 'frontendRoots' => ['resources/js']])))->violations($rule), 'target');

        expect(array_values(array_unique($targets)))->toBe(['resources/js/pages/Posts.vue']);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
})->with('kit-owned violations');

test('the frontend rules skip a page tey/mod records as kit-generated while it is unchanged, and check an edited or unrecorded one', function (string $rule, string $source) {
    $page = 'resources/js/pages/Workspace/Settings/Members/Page.vue';
    $base = kitOwnershipApp([
        $page => $source,
        'resources/js/pages/Workspace/Settings/Billing/Page.vue' => $source.'<!-- edited -->',
        'resources/js/pages/Posts.vue' => $source,
        '.mod-maintenance/installed/'.hash('sha256', 'tey/kit').'.json' => kitInstalledRecord([$page => $source, 'resources/js/pages/Workspace/Settings/Billing/Page.vue' => $source]),
    ]);
    $run = fn (): array => array_values(array_unique(array_column((new Conformance(frontendConfiguration(overrides: ['base' => $base, 'frontendRoots' => ['resources/js']])))->violations($rule), 'target')));
    try {
        expect($run())->toBe(['resources/js/pages/Posts.vue', 'resources/js/pages/Workspace/Settings/Billing/Page.vue']);

        // A record that is not tey/mod's installed format names nothing.
        file_put_contents($base.'/.mod-maintenance/installed/'.hash('sha256', 'tey/kit').'.json', kitInstalledRecord([$page => $source], owner: 'someone/else'));
        expect($run())->toContain($page);

        // The 0.8 single-lineage record is read too.
        unlink($base.'/.mod-maintenance/installed/'.hash('sha256', 'tey/kit').'.json');
        file_put_contents($base.'/.mod-maintenance/installed.json', kitInstalledRecord([$page => $source]));
        expect($run())->not->toContain($page);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
})->with('kit-owned violations');

function headerActionsViewModel(string $actions, string $base = 'PageViewModel'): string
{
    return <<<PHP
        <?php

        namespace App\ViewModels;

        use Tey\Kit\Features\Core\Data\Action;
        use Tey\Kit\Features\Core\ViewModels\\{$base};

        final class PostViewModel extends {$base}
        {
            public function title(): string
            {
                return 'Post';
            }

            public function actions(): array
            {
                return [{$actions}];
            }
        }
        PHP;
}

test('ui header-actions reads a page ViewModel\'s literal actions and a #actions slot', function (array $files, array $checks) {
    $base = kitOwnershipApp($files);
    try {
        $config = frontendConfiguration(overrides: ['base' => $base, 'sourceRoots' => ['app'], 'frontendRoots' => ['resources/js'], 'optionalRules' => ['ui header-actions']]);

        expect(array_column((new Conformance($config))->violations('ui header-actions'), 'check'))->toBe($checks);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
})->with([
    'three without priority' => [['app/ViewModels/PostViewModel.php' => headerActionsViewModel("new Action('Edit', '/e'), new Action('Copy', '/c'), new Action('Share', '/s')")], []],
    'four without priority' => [['app/ViewModels/PostViewModel.php' => headerActionsViewModel("new Action('Edit', '/e'), new Action('Copy', '/c'), new Action('Share', '/s'), new Action('Archive', '/a')")],
        ['more than 3 header actions with no priority; set priority to choose the visible ones']],
    'four with a primary' => [['app/ViewModels/PostViewModel.php' => headerActionsViewModel("new Action('Edit', '/e', priority: 'primary'), new Action('Copy', '/c'), new Action('Share', '/s'), new Action('Archive', '/a')")], []],
    'two primaries' => [['app/ViewModels/PostViewModel.php' => headerActionsViewModel("new Action('Edit', '/e', priority: 'primary'), Action::disabled('Publish', '/p', 'post', 'Draft', priority: 'primary')")],
        ['header actions declare more than one primary']],
    'four visible' => [['app/ViewModels/PostViewModel.php' => headerActionsViewModel("new Action('Edit', '/e', priority: 'primary'), new Action('Copy', '/c', priority: 'secondary'), new Action('Share', '/s', priority: 'secondary'), new Action('Archive', '/a', priority: 'secondary')")],
        ['header actions show more than 3; mark the rest overflow']],
    'a workbench ViewModel' => [['app/ViewModels/PostViewModel.php' => headerActionsViewModel("new Action('A', '/a'), new Action('B', '/b'), new Action('C', '/c'), new Action('D', '/d')", 'WorkbenchViewModel')],
        ['more than 3 header actions with no priority; set priority to choose the visible ones']],
    'a non-page class' => [['app/Support/PostActions.php' => str_replace('extends PageViewModel', '', headerActionsViewModel("new Action('A', '/a'), new Action('B', '/b'), new Action('C', '/c'), new Action('D', '/d')"))], []],
    'a slot with four controls' => [['resources/js/pages/Posts.vue' => '<template><Page><template #actions><Button>A</Button><Button>B</Button><a href="/c">C</a><button>D</button></template></Page></template>'],
        ['header #actions slot holds more than 3 controls; pass descriptors to Page :actions']],
    'a slot with three controls' => [['resources/js/pages/Posts.vue' => '<template><Page><template #actions><Button>A</Button><Button>B</Button><button>C</button></template></Page></template>'], []],
]);

test('ui header-actions is optional and skips the kit tree', function () {
    $slot = '<template><Page><template #actions><Button>A</Button><Button>B</Button><Button>C</Button><Button>D</Button></template></Page></template>';
    $base = kitOwnershipApp(['resources/js/components/Kit/Layout/Demo.vue' => $slot, 'resources/js/pages/Posts.vue' => $slot]);
    try {
        $disabled = new Conformance(frontendConfiguration(overrides: ['base' => $base, 'frontendRoots' => ['resources/js'], 'optionalRules' => []]));
        expect($disabled->violations('ui header-actions'))->toBe([]);
        expect(fn () => $disabled->assert('ui header-actions'))->toThrow(SkippedWithMessageException::class, 'disabled');

        $enabled = new Conformance(frontendConfiguration(overrides: ['base' => $base, 'frontendRoots' => ['resources/js'], 'optionalRules' => ['ui header-actions']]));
        expect(array_column($enabled->violations('ui header-actions'), 'target'))->toBe(['resources/js/pages/Posts.vue']);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});
