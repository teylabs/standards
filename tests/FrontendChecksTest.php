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
        optionalRules: ['ui plus', 'ui new-tab', 'ui back-strip', 'ui dropzone', 'ui toast'],
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
    foreach (['ui plus', 'ui new-tab', 'ui back-strip', 'ui dropzone', 'ui toast'] as $rule) {
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
    'hand-written back link' => ['ui back-strip', '<template><Link href="/assignments/1"><ArrowLeft class="size-4" /> Back to A-0001</Link></template>', true],
    'bare back link with an arrow' => ['ui back-strip', '<template><a :href="parentUrl">← Back</a></template>', true],
    'back link by label' => ['ui back-strip', '<template><Button label="Back to assignments" :href="route(\'assignments.index\')" /></template>', true],
    'back link with an interpolated parent' => ['ui back-strip', '<template><Link :href="parent.url">Back to {{ parent.title }}</Link></template>', true],
    'wizard back button without href' => ['ui back-strip', '<template><button type="button" @click="step--">Back</button></template>', false],
    'link whose label only starts with back' => ['ui back-strip', '<template><Link href="/backups">Backups</Link><Link href="/x">Background checks</Link></template>', false],
    'link that mentions back later' => ['ui back-strip', '<template><Link href="/orders">Go back to orders</Link></template>', false],
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
    'styling.pure-tailwind' => ['styling.pure-tailwind', '<template><p style="color: red">Posts</p></template>'],
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

test('the frontend rules never scan kit 0.18\'s components in vendor/tey/kit, even under a configured root, and still check the same code in the app', function (string $rule, string $source) {
    $base = kitOwnershipApp(['vendor/tey/kit/resources/js/components/Kit/Form/FileInput.vue' => $source, 'resources/js/pages/Posts.vue' => $source]);
    try {
        $targets = array_column((new Conformance(frontendConfiguration(overrides: ['base' => $base, 'frontendRoots' => ['resources/js', 'vendor/tey/kit/resources/js']])))->violations($rule), 'target');

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

        // A CRLF checkout of an unedited page is still the kit's.
        file_put_contents($base.'/'.$page, str_replace("\n", "\r\n", $source."\n"));
        file_put_contents($base.'/.mod-maintenance/installed/'.hash('sha256', 'tey/kit').'.json', kitInstalledRecord([$page => $source."\n", 'resources/js/pages/Workspace/Settings/Billing/Page.vue' => $source]));
        expect($run())->toBe(['resources/js/pages/Posts.vue', 'resources/js/pages/Workspace/Settings/Billing/Page.vue']);
        file_put_contents($base.'/'.$page, $source);

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
        ['header #actions slot holds more than 3 controls; keep up to 3 visible and put the rest in a ButtonGroup #menu']],
    'explicit actions with a ButtonGroup menu' => [['resources/js/pages/Posts.vue' => '<template><Page><template #actions><ButtonGroup><InertiaAction :action="a.edit" /><InertiaAction :action="a.copy" /><template #menu><DropdownAction :action="a.share" /><DropdownAction :action="a.archive" /><DropdownMenuItem>X</DropdownMenuItem></template></ButtonGroup></template></Page></template>'], []],
    'four InertiaActions' => [['resources/js/pages/Posts.vue' => '<template><Page><template #actions><ButtonGroup><InertiaAction :action="a.a" /><InertiaAction :action="a.b" /><InertiaAction :action="a.c" /><InertiaAction :action="a.d" /></ButtonGroup></template></Page></template>'],
        ['header #actions slot holds more than 3 controls; keep up to 3 visible and put the rest in a ButtonGroup #menu']],
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

describe('vue.bare-handler-argument', function () {
beforeEach(function () {
    $this->bareBase = sys_get_temp_dir().'/standards-bare-'.bin2hex(random_bytes(4));
    mkdir($this->bareBase.'/resources/js/components', 0777, true);
    $this->bare = function (string $vue): array {
        file_put_contents($this->bareBase.'/resources/js/components/Thing.vue', $vue);

        return array_column((new Conformance(frontendConfiguration('Good', ['base' => $this->bareBase])))->violations('vue.bare-handler-argument'), 'check');
    };
});

afterEach(function () {
    (new Illuminate\Filesystem\Filesystem)->deleteDirectory($this->bareBase);
});

test('vue.bare-handler-argument is advisory', function () {
    expect(Tey\Standards\Enforcement\Gate::ADVISORY)->toContain('vue.bare-handler-argument');
});

test('a bare handler on a native element or kit Button whose first parameter is not an Event is a finding', function () {
    $script = <<<'TS'
        <script setup lang="ts">
        import Button from '@/components/Kit/Form/Button.vue';
        import Picker from './Picker.vue';
        const props = defineProps<{ start: { url: string } }>();
        async function begin(url: string = props.start.url) {}
        const remove = (id: number) => {};
        function toggle(event: MouseEvent) {}
        const open = (e) => {};
        function save() {}
        const pick = (value: string) => {};
        </script>
        TS;

    // AB's Issue confirm: a kit Button and a native button, both bare.
    expect(($this->bare)($script.'<template><Button @click="begin">Issue</Button><button type="button" @click.stop="remove">x</button></template>'))->toBe([
        '@click="begin" on <Button> passes the event as begin\'s first argument (url: string = props.start.url): call it, @click="begin()"',
        '@click="remove" on <button> passes the event as remove\'s first argument (id: number): call it, @click="remove()"',
    ]);
    // Calls, Event parameters, no parameters and a custom component's own event are fine.
    expect(($this->bare)($script.'<template><Button @click="begin()">Issue</Button><button @click="toggle" /><a @click="open" /><button @click="save" /><Picker @click="pick" @select="pick" /><input @input="toggle" /></template>'))->toBe([]);
});

test('a handler not declared in the file is not judged', function () {
    expect(($this->bare)('<script setup lang="ts">import { useThing } from "./thing"; const { run } = useThing();</script><template><button @click="run" /></template>'))->toBe([]);
});
});

describe('inertia.pure', function () {
    beforeEach(function () {
        $this->pureBase = sys_get_temp_dir().'/standards-pure-'.bin2hex(random_bytes(4));
        mkdir($this->pureBase.'/resources/js/pages', 0777, true);
        mkdir($this->pureBase.'/app/Http/Controllers', 0777, true);
        $this->pure = function (array $files): array {
            foreach ($files as $path => $text) {
                file_put_contents($this->pureBase.'/'.$path, $text);
            }

            return (new Conformance(frontendConfiguration('Good', ['base' => $this->pureBase, 'classRoots' => ['app' => 'App\\']])))->violations('inertia.pure');
        };
    });

    afterEach(function () {
        (new Illuminate\Filesystem\Filesystem)->deleteDirectory($this->pureBase);
    });

    test('inertia.pure is advisory', function () {
        expect(Tey\Standards\Enforcement\Gate::ADVISORY)->toContain('inertia.pure');
    });

    test('JSON endpoints and fetch/axios/useHttp in your own UI are findings', function () {
        $php = <<<'PHP'
            <?php
            namespace App\Http\Controllers;
            use Illuminate\Http\JsonResponse;
            final class TicketStateController
            {
                public function show(): JsonResponse
                {
                    if (request()->expectsJson()) {
                        return response()->json(['ok' => true]);
                    }
                }
            }
            PHP;
        $vue = "<script setup lang=\"ts\">\nimport axios from 'axios';\nimport { useHttp } from '@inertiajs/vue3';\nconst r = await fetch('/tickets/1/state');\n</script>\n";
        $found = ($this->pure)(['app/Http/Controllers/TicketStateController.php' => $php, 'resources/js/pages/Ticket.vue' => $vue]);

        expect(array_map(fn (array $v): string => $v['target'].' '.strtok($v['check'], ' '), $found))->toBe([
            'app/Http/Controllers/TicketStateController.php JsonResponse',
            'app/Http/Controllers/TicketStateController.php expectsJson()',
            'app/Http/Controllers/TicketStateController.php response()->json(',
            'resources/js/pages/Ticket.vue axios',
            'resources/js/pages/Ticket.vue useHttp',
            'resources/js/pages/Ticket.vue fetch(',
        ]);
    });

    test('an allowed surface with a category and a reason is exempt; a webhook controller by name; comments are not hits', function () {
        $php = <<<'PHP'
            <?php
            namespace App\Http\Controllers;
            final class ReportPdfController
            {
                public function show()
                {
                    // inertia.pure: binary the PDF download is a file, not a page
                    return response()->json(['url' => 'signed']);
                }
            }
            PHP;
        $vue = "<script setup lang=\"ts\">\n// Never call fetch( here: use a partial reload.\nconst hits = await fetch('/search?q=' + q); // inertia.pure: typeahead suggestions while typing\n</script>\n";
        $hook = "<?php\nfinal class StripeWebhookController { public function __invoke() { return response()->json([]); } }\n";

        expect(($this->pure)(['app/Http/Controllers/ReportPdfController.php' => $php, 'app/Http/Controllers/StripeWebhookController.php' => $hook, 'resources/js/pages/Search.vue' => $vue]))->toBe([]);
    });

    test('a marker with an unknown category or no reason is itself a finding', function () {
        $vue = "<script setup lang=\"ts\">\n// inertia.pure: convenience it was quicker\nawait fetch('/a');\n// inertia.pure: typeahead\nawait fetch('/b');\n</script>\n";

        expect(array_column(($this->pure)(['resources/js/pages/X.vue' => $vue]), 'check'))->toBe([
            'fetch(: `inertia.pure: convenience` is not an allowed category ('.implode(', ', Tey\Standards\Checks\InertiaPure::CATEGORIES).')',
            'fetch(: `inertia.pure: typeahead` needs a one-line reason',
        ]);
    });
});
