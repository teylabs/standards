<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Checks\Layout;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;
use Tey\Standards\Enforcement\Gate;

/** The layout rules on a scratch app: each test writes the .vue files it needs under resources/js. */
function layoutConfiguration(string $base, array $overrides = []): Configuration
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

/** @param array<string, string> $files path under resources/js => template body (wrapped in <template> unless it already has one) */
function layoutApp(array $files): string
{
    $base = sys_get_temp_dir().'/standards-layout-'.bin2hex(random_bytes(6));
    foreach ($files as $path => $body) {
        $file = $base.'/resources/js/'.$path;
        is_dir(dirname($file)) || mkdir(dirname($file), 0777, true);
        file_put_contents($file, str_starts_with(ltrim($body), '<template>') ? $body : "<template>\n{$body}\n</template>\n");
    }

    return $base;
}

/** @return list<string> "path:line check" for each finding, advisory ones marked */
function layoutFindings(string $rule, array $files, array $overrides = []): array
{
    $base = layoutApp($files);

    try {
        return array_map(fn (array $v): string => $v['target'].':'.$v['line'].' '.$v['check'].(($v['advisory'] ?? false) ? ' [advisory]' : ''),
            (new Conformance(layoutConfiguration($base, $overrides)))->violations($rule));
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
}

test('the layout rules are registered and advisory until opted in', function () {
    expect(Gate::ADVISORY)->toContain(...Layout::RULES)
        ->and(array_keys(Conformance::TESTS))->toContain(...Layout::RULES)
        ->and(json_decode(file_get_contents(dirname(__DIR__).'/resources/patterns/kit-exports.json'), true)['components'])->toContain('Stack', 'BlockStack');
});

test('ui.action-group flags adjacent action controls outside a group', function () {
    expect(layoutFindings(Layout::ACTION_GROUP, ['pages/Posts.vue' => <<<'VUE'
        <div class="flex">
            <Button>Save</Button>
            <Button variant="outline">Cancel</Button>
        </div>
        <footer>
            <button>One</button>
            <Link as="button" href="/x">Two</Link>
        </footer>
        VUE]))->toBe([
        'resources/js/pages/Posts.vue:3 adjacent action controls in <div> are not grouped: put them in a ButtonGroup, or render descriptors through ActionBar',
        'resources/js/pages/Posts.vue:7 adjacent action controls in <footer> are not grouped: put them in a ButtonGroup, or render descriptors through ActionBar',
    ]);
});

test('ui.action-group passes grouped controls and a Toolbar and lone or separated buttons and v-else alternatives', function () {
    expect(layoutFindings(Layout::ACTION_GROUP, ['pages/Posts.vue' => <<<'VUE'
        <ButtonGroup><Button>A</Button><Button>B</Button></ButtonGroup>
        <Toolbar><SearchBox /><Button>A</Button><Button>B</Button></Toolbar>
        <IndexToolbar><Button>A</Button><Button>B</Button></IndexToolbar>
        <FormActions><Button>A</Button><button>B</button></FormActions>
        <Page><template #actions><Button>A</Button><Button>B</Button></template></Page>
        <div><Button>A</Button><span>or</span><Button>B</Button></div>
        <div><Button v-if="a">A</Button><Button v-else>B</Button></div>
        <div><Link href="/a">A</Link><Link href="/b">B</Link></div>
        VUE]))->toBe([]);
});

test('ui.action-group passes the kit ActionGroup in a column, stacked full-width as on an auth card, and in a row', function () {
    expect(layoutFindings(Layout::ACTION_GROUP, [
        'pages/Auth/TwoFactor.vue' => <<<'VUE'
            <template>
                <Form action="/two-factor-challenge" method="post" class="grid gap-4">
                    <Field id="code" name="code" label="Authentication code"><Input id="code" name="code" /></Field>
                    <ActionGroup direction="column">
                        <Button type="submit">Verify</Button>
                        <Button variant="secondary" type="button" @click="recovery = !recovery">Use a recovery code</Button>
                    </ActionGroup>
                </Form>
                <action-group direction="column" class="gap-4">
                    <Button>Send the link again</Button>
                    <Link href="/logout" method="post" as="button" class="w-full">Sign out</Link>
                </action-group>
            </template>
            <script setup lang="ts">
            import { ActionGroup, Button, Field, Input } from '@kit/ui';
            </script>
            VUE,
        'pages/Settings/Profile.vue' => <<<'VUE'
            <template>
                <Form class="grid gap-4"><Group><Button type="submit">Save</Button><Button variant="outline">Cancel</Button></Group></Form>
                <ActionGroup direction="row"><Button>Export</Button><Button>Import</Button></ActionGroup>
            </template>
            <script setup lang="ts">
            import { Button } from '@/components/Kit';
            import { ActionGroup as Group } from '@/components/Kit';
            import ActionGroup from '@/components/Kit/Action/ActionGroup.vue';
            </script>
            VUE,
    ]))->toBe([]);
});

test('ui.action-group passes the kit 0.21 Login page as shipped, and its ActionGroup column where the page does not import it', function () {
    // The kit's own page, from kit's session-ui stub: Sign in and the passkey button are direct children of <ActionGroup direction="column">.
    $login = (string) file_get_contents(dirname(__DIR__).'/fixtures/Layout/KitLogin/resources/js/pages/Auth/Login/Page.vue');
    $unimported = str_replace('import { ActionGroup, Alert,', 'import { Alert,', $login);
    // The page as written (layoutApp would wrap a file that opens with its script in another <template>).
    $findings = function (string $page): array {
        $base = sys_get_temp_dir().'/standards-layout-'.bin2hex(random_bytes(6));
        mkdir($base.'/resources/js/pages/Auth/Login', 0777, true);
        file_put_contents($base.'/resources/js/pages/Auth/Login/Page.vue', $page);
        try {
            return array_map(fn (array $v): string => $v['line'].' '.$v['check'], (new Conformance(layoutConfiguration($base)))->violations(Layout::ACTION_GROUP));
        } finally {
            (new Filesystem)->deleteDirectory($base);
        }
    };
    expect($unimported)->not->toBe($login)
        ->and($findings($login))->toBe([])
        ->and($findings($unimported))->toBe([])
        // A control beside the group, not in it, is still a run.
        ->and($findings(str_replace('</ActionGroup>', '</ActionGroup><Button variant="outline">Cancel</Button><Button>Help</Button>', $unimported)))
        ->toBe(['93 adjacent action controls in <Form> are not grouped: put them in a ButtonGroup, or render descriptors through ActionBar']);
});

test('ui.action-group points hand-rolled stacked full-width actions at an ActionGroup column, and counts only the kit ActionGroup', function () {
    expect(layoutFindings(Layout::ACTION_GROUP, [
        'pages/Auth/Login.vue' => <<<'VUE'
            <template>
                <Form action="/login" method="post" class="grid gap-4">
                    <Field id="email" name="email" label="Email"><Input id="email" name="email" /></Field>
                    <Button type="submit" class="w-full">Sign in</Button>
                    <Button variant="secondary" type="button" class="w-full">Sign in with a passkey</Button>
                </Form>
                <div class="flex flex-col gap-3">
                    <Button class="w-full">Accept</Button>
                    <Button variant="outline">Cancel</Button>
                </div>
            </template>
            <script setup lang="ts">
            import { Button, Field, Input } from '@kit/ui';
            </script>
            VUE,
        'pages/Auth/Invitation.vue' => <<<'VUE'
            <template>
                <ActionGroup direction="column"><Button>Accept</Button><Button>Switch account</Button></ActionGroup>
            </template>
            <script setup lang="ts">
            import ActionGroup from './ActionGroup.vue';
            </script>
            VUE,
    ]))->toBe([
        'resources/js/pages/Auth/Invitation.vue:2 adjacent action controls in <ActionGroup> are not grouped: put them in a ButtonGroup, or render descriptors through ActionBar',
        'resources/js/pages/Auth/Login.vue:4 stacked full-width action controls in <Form> are not grouped: put them in <ActionGroup direction="column">, primary first, and drop their w-full',
        'resources/js/pages/Auth/Login.vue:8 adjacent action controls in <div> are not grouped: put them in a ButtonGroup, or render descriptors through ActionBar',
    ]);
});

test('ui.row-actions flags controls in a DataTable cell slot and a hand-rolled table with actions in its cells', function () {
    expect(layoutFindings(Layout::ROW_ACTIONS, ['pages/Posts.vue' => <<<'VUE'
        <DataTable :data="rows">
            <template #cell-name="{ row }"><Link :href="row.url">{{ row.name }}</Link></template>
            <template #cell-done="{ row }"><Checkbox :model-value="row.done" /></template>
            <template #row-actions="{ row }"><Button @click="remove(row)">Delete</Button></template>
        </DataTable>
        <table>
            <tr><td>{{ a }}</td><td><DropdownMenu /></td></tr>
        </table>
        <table><tr><td>plain</td></tr></table>
        VUE]))->toBe([
        'resources/js/pages/Posts.vue:5 DataTable #row-actions slot hand-places row actions: put them on the row as ActionDescriptor[] (actions) for the row ActionBar',
        'resources/js/pages/Posts.vue:7 row actions hand-placed in a hand-rolled <table>: render it as a DataTable, with the row actions as descriptors on the row',
    ]);
});

test('ui.page-title-dup flags a repeated title and a page-level SectionHeader and a create action outside the header', function () {
    expect(layoutFindings(Layout::PAGE_TITLE, ['pages/Posts.vue' => <<<'VUE'
        <Page title="Posts">
            <SectionHeader title="Posts" />
            <SectionHeader title="Drafts" size="page" />
            <PageSection title="Archive" />
            <Button :href="route('posts.create')">New post</Button>
            <Button @click="open">Add filter</Button>
            <EmptyState><Button :href="route('posts.create')">Create your first post</Button></EmptyState>
            <template #actions><Button :href="route('posts.create')">New post</Button></template>
        </Page>
        VUE]))->toBe([
        'resources/js/pages/Posts.vue:3 <SectionHeader> repeats the Page title "Posts": the heading is Page\'s; drop it or give the section its own title',
        'resources/js/pages/Posts.vue:4 <SectionHeader size="page"> draws a second page heading inside a Page that draws its own: drop it, or pass the title to Page',
        'resources/js/pages/Posts.vue:6 create action (posts.create) sits outside the Page header: pass it in <Page :actions>',
        'resources/js/pages/Posts.vue:7 a "Add filter" action sits outside the Page header: if it creates the page\'s record, pass it in <Page :actions> [advisory]',
    ]);
});

test('ui.page-title-dup passes a Page that hides its heading and a dynamic Page title', function () {
    expect(layoutFindings(Layout::PAGE_TITLE, ['pages/Posts.vue' => <<<'VUE'
        <Page :heading="false"><SectionHeader title="Posts" size="page" /></Page>
        <Page :title="post.title"><SectionHeader title="Posts" /></Page>
        VUE]))->toBe([]);
});

test('ui.no-outer-margins flags margins on Page children and on the root of a component the Page renders', function () {
    expect(layoutFindings(Layout::OUTER_MARGINS, [
        'pages/Posts.vue' => <<<'VUE'
            <template>
                <Page>
                    <div class="mt-6 flex">a</div>
                    <Card :class="open ? 'md:mb-4' : ''" />
                    <PostList />
                    <div class="p-4"><div class="mt-2">nested is fine</div></div>
                </Page>
            </template>
            <script setup lang="ts">
            import PostList from '@/components/PostList.vue';
            </script>
            VUE,
        'components/PostList.vue' => '<section class="space-y-4 -mt-2"><p>x</p></section>',
    ]))->toBe([
        'resources/js/pages/Posts.vue:3 <div> directly in <Page> carries mt-6: the Page\'s gap owns the spacing; use Stack (gap-*) or PageSection',
        'resources/js/pages/Posts.vue:4 <Card> directly in <Page> carries md:mb-4: the Page\'s gap owns the spacing; use Stack (gap-*) or PageSection',
        'resources/js/components/PostList.vue:2 root element carries space-y-4 -mt-2 and a Page renders it directly (resources/js/pages/Posts.vue): the Page\'s gap owns the spacing; drop the margin',
    ]);
});

test('ui.no-outer-margins flags a flush Page with several blocks but not one child with out-of-flow companions', function () {
    expect(layoutFindings(Layout::OUTER_MARGINS, [
        'pages/Board.vue' => <<<'VUE'
            <Page flush>
                <Workbench />
                <Teleport to="body"><div /></Teleport>
                <Dialog />
                <div class="fixed inset-0" />
                <template #actions><Button>A</Button></template>
            </Page>
            VUE,
        'pages/Split.vue' => <<<'VUE'
            <Page flush>
                <SplitPane v-if="ready" />
                <EmptyState v-else />
            </Page>
            VUE,
        'pages/Wrong.vue' => "<Page flush>\n<Card />\n<Card />\n</Page>",
    ]))->toBe([
        'resources/js/pages/Wrong.vue:2 <Page flush> holds more than one in-flow child: flush is for one full-bleed child; remove flush and let the Page gap its blocks',
    ]);
});

test('ui.page-grid flags page-level grid-cols and col-span but not grids inside a card or the widget grid', function () {
    expect(layoutFindings(Layout::PAGE_GRID, ['pages/Dash.vue' => <<<'VUE'
        <Page>
            <div class="grid gap-6 lg:grid-cols-3"><div>a</div></div>
            <div class="contents"><div class="lg:col-span-2">b</div></div>
            <Card><div class="grid grid-cols-2">c</div></Card>
            <div class="grid grid-cols-4"><StatWidget /></div>
        </Page>
        VUE]))->toBe([
        'resources/js/pages/Dash.vue:3 page-level grid-cols on a Page child: use PageGrid with a preset (main-aside, halves, thirds)',
        'resources/js/pages/Dash.vue:4 page-level col-span: use PageGridItem :span inside a PageGrid',
    ]);
});

test('ui.primitive-pointers points hand-rolled shapes to the primitive and stays advisory for one the kit has not shipped', function () {
    $findings = layoutFindings(Layout::PRIMITIVES, [
        'pages/Settings.vue' => <<<'VUE'
            <Page>
                <div class="flex flex-col gap-4"><Card /><Card /></div>
                <section><h2>Profile</h2><Card /></section>
                <div class="grid grid-cols-3"><Link v-for="i in items" :href="i.url">{{ i.label }}</Link></div>
                <div class="md:grid-cols-3 grid"><div>Title</div><Card class="md:col-span-2" /></div>
                <div class="flex items-center"><hr /><h3>Or</h3><hr /></div>
            </Page>
            VUE,
        'components/BetaBanner.vue' => '<div>beta</div>',
    ]);

    expect($findings)->toContain(
        "resources/js/pages/Settings.vue:3 hand-rolled Stack (a flex-col gap or space-y div stacking blocks): use the kit's Stack",
        "resources/js/pages/Settings.vue:4 hand-rolled PageSection (a section with an h2 directly in Page): use the kit's PageSection",
        "resources/js/pages/Settings.vue:5 hand-rolled NavGrid (a grid of Link cards): use the kit's NavGrid [advisory]",
        "resources/js/pages/Settings.vue:6 hand-rolled CardSection (a md:grid-cols-3 settings section with a md:col-span-2 card): use the kit's CardSection [advisory]",
        "resources/js/pages/Settings.vue:7 hand-rolled DividerSection (a heading centred between rules): use the kit's DividerSection [advisory]",
        "resources/js/components/BetaBanner.vue:1 hand-rolled Banner (an app-local banner component): use the kit's Banner [advisory]",
    );
});

test('ui.primitive-pointers reads an installed kit: a shipped primitive fails and Stack falls back to BlockStack', function () {
    $base = layoutApp(['pages/Settings.vue' => "<Page>\n<div class=\"space-y-6\"><Card /><Card /></div>\n<section><h2>A</h2><Card /></section>\n</Page>"]);

    try {
        $barrel = $base.'/vendor/tey/kit/'.Tey\Standards\Patterns\KitExports::BARREL;
        mkdir(dirname($barrel), 0777, true);
        file_put_contents($barrel, "export { default as BlockStack } from './BlockStack.vue';\nexport { default as PageSection } from './PageSection.vue';\n");

        expect(array_map(fn (array $v): string => $v['check'].(($v['advisory'] ?? false) ? ' [advisory]' : ''), (new Conformance(layoutConfiguration($base)))->violations(Layout::PRIMITIVES)))->toBe([
            "hand-rolled PageSection (a section with an h2 directly in Page): use the kit's PageSection",
            "hand-rolled Stack (a flex-col gap or space-y div stacking blocks): use the kit's BlockStack",
        ]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('an opted-in layout rule fails on a new finding and passes a baselined one while the advisory label test never fails', function () {
    $base = layoutApp(['pages/Posts.vue' => "<Page>\n<div class=\"mt-4\">a</div>\n<Button @click=\"x\">Add tag</Button>\n</Page>"]);

    try {
        $check = '<div> directly in <Page> carries mt-4: the Page\'s gap owns the spacing; use Stack (gap-*) or PageSection';
        $entry = ['rule' => Layout::OUTER_MARGINS, 'target' => 'resources/js/pages/Posts.vue', 'check' => $check, 'reason' => 'baseline', 'date' => '2026-11-07', 'expires' => '2099-01-01'];
        $optedIn = ['optionalRules' => [Layout::OUTER_MARGINS, Layout::PAGE_TITLE]];

        expect(fn () => (new Conformance(layoutConfiguration($base, $optedIn)))->assert(Layout::OUTER_MARGINS))->toThrow(Exception::class, 'violation: resources/js/pages/Posts.vue | '.$check);
        (new Conformance(layoutConfiguration($base, [...$optedIn, 'allowlist' => [$entry]])))->assert(Layout::OUTER_MARGINS);
        (new Conformance(layoutConfiguration($base, $optedIn)))->assert(Layout::PAGE_TITLE);

        // A baselined advisory finding (the label test) is not stale once the rule is opted in.
        $label = ['rule' => Layout::PAGE_TITLE, 'target' => 'resources/js/pages/Posts.vue', 'check' => 'a "Add tag" action sits outside the Page header: if it creates the page\'s record, pass it in <Page :actions>', 'reason' => 'baseline', 'date' => '2026-11-07', 'expires' => '2099-01-01'];
        (new Conformance(layoutConfiguration($base, [...$optedIn, 'allowlist' => [$label]])))->assert(Layout::PAGE_TITLE);
        $opted = (new Gate(layoutConfiguration($base, [...$optedIn, 'allowlist' => [$entry, $label]]), new DateTimeImmutable('2026-11-07'), Tey\Standards\Patterns\TreeCatalog::shipped(), 14))->run();
        expect(array_filter($opted['violations'], fn (array $v): bool => in_array($v['rule'], Layout::RULES, true)))->toBe([]);

        $report = (new Gate(layoutConfiguration($base), new DateTimeImmutable('2026-11-07'), Tey\Standards\Patterns\TreeCatalog::shipped(), 14))->run();
        expect(array_column(array_filter($report['advisories'], fn (array $a): bool => in_array($a['rule'], Layout::RULES, true)), 'rule'))->toContain(Layout::OUTER_MARGINS, Layout::PAGE_TITLE)
            ->and(array_filter($report['violations'], fn (array $v): bool => in_array($v['rule'], Layout::RULES, true)))->toBe([]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('opted in each layout rule rejects the seeded Bad fixture page and accepts the Good one', function () {
    foreach (Layout::RULES as $rule) {
        $optIn = fn (string $kind): Configuration => new Configuration(...array_replace(get_object_vars(layoutConfiguration(dirname(__DIR__).'/fixtures/Layout/'.$kind)), ['optionalRules' => [$rule]]));
        (new Conformance($optIn('Good')))->assert($rule);
        expect(fn () => (new Conformance($optIn('Bad')))->assert($rule))->toThrow(PHPUnit\Framework\AssertionFailedError::class, 'violation:');
    }
});

test('an allowlisted advisory layout finding that disappears is not a stale failure', function () {
    $base = layoutApp(['pages/Posts.vue' => "<Page>\n<p>nothing here</p>\n</Page>"]);

    try {
        $entries = [
            ['rule' => Layout::PAGE_TITLE, 'target' => 'resources/js/pages/Posts.vue', 'check' => 'a "Add tag" action sits outside the Page header: if it creates the page\'s record, pass it in <Page :actions>', 'reason' => 'baseline', 'date' => '2026-11-07', 'expires' => '2099-01-01'],
            ['rule' => Layout::PRIMITIVES, 'target' => 'resources/js/pages/Posts.vue', 'check' => "hand-rolled NavGrid (a grid of Link cards): use the kit's NavGrid", 'reason' => 'baseline', 'date' => '2026-11-07', 'expires' => '2099-01-01'],
        ];
        foreach ([[], [Layout::PAGE_TITLE, Layout::PRIMITIVES]] as $optionalRules) {
            $config = layoutConfiguration($base, ['optionalRules' => $optionalRules, 'allowlist' => $entries]);
            foreach ([Layout::PAGE_TITLE, Layout::PRIMITIVES] as $rule) {
                try {
                    (new Conformance($config))->assert($rule);
                } catch (PHPUnit\Framework\SkippedWithMessageException) {
                }
            }
            $report = (new Gate($config, new DateTimeImmutable('2026-11-07'), Tey\Standards\Patterns\TreeCatalog::shipped(), 14))->run();
            expect(array_filter($report['violations'], fn (array $v): bool => in_array($v['rule'], Layout::RULES, true)))->toBe([]);
        }
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('ui.action-group and ui.page-title-dup read through wrappers in an allowed container and a SectionHeader actions slot', function () {
    expect(layoutFindings(Layout::ACTION_GROUP, ['pages/Posts.vue' => <<<'VUE'
        <Toolbar><div><Button>A</Button><Button>B</Button></div></Toolbar>
        <Page><template #actions><div><Button>A</Button><Button>B</Button></div></template></Page>
        VUE]))->toBe([])
        ->and(layoutFindings(Layout::PAGE_TITLE, ['pages/Posts.vue' => "<Page>\n<SectionHeader title=\"Recent\"><template #actions><Button :href=\"route('posts.create')\">New</Button></template></SectionHeader>\n</Page>"]))
        ->toBe(["resources/js/pages/Posts.vue:3 create action (posts.create) sits outside the Page header: pass it in <Page :actions>"])
        ->and(layoutFindings(Layout::OUTER_MARGINS, ['pages/Posts.vue' => "<Page>\n<div class=\"-mx-4 lg:-m-2 mx-auto\">a</div>\n</Page>"]))
        ->toBe(["resources/js/pages/Posts.vue:3 <div> directly in <Page> carries -mx-4 lg:-m-2: the Page's gap owns the spacing; use Stack (gap-*) or PageSection"]);
});

test('an installed kit from before the Stack rename is neither missing Stack nor ahead with BlockStack', function () {
    $kit = sys_get_temp_dir().'/standards-kit-rename-'.bin2hex(random_bytes(6));
    $barrel = $kit.'/vendor/tey/kit/'.Tey\Standards\Patterns\KitExports::BARREL;

    try {
        mkdir(dirname($barrel), 0777, true);
        $names = array_map(fn (string $name): string => $name === 'Stack' ? 'BlockStack' : $name, Tey\Standards\Patterns\KitExports::snapshot());
        file_put_contents($barrel, implode("\n", array_map(fn (string $name): string => "export { default as {$name} } from './{$name}.vue';", array_unique($names))));

        expect(Tey\Standards\Patterns\KitExports::mismatch($kit))->toBeNull()
            ->and(Tey\Standards\Patterns\KitExports::lag($kit))->toBeNull();
    } finally {
        (new Filesystem)->deleteDirectory($kit);
    }
});

/** @param array<string, string> $files path under the app => source; PHP is read from app/, Vue from resources/js; advisory findings are marked */
function rowLabelFindings(array $files): array
{
    $base = sys_get_temp_dir().'/standards-row-label-'.bin2hex(random_bytes(6));
    foreach ($files as $path => $source) {
        is_dir(dirname($base.'/'.$path)) || mkdir(dirname($base.'/'.$path), 0777, true);
        file_put_contents($base.'/'.$path, $source);
    }

    try {
        return array_map(fn (array $v): string => $v['target'].':'.$v['line'].' '.$v['check'].(($v['advisory'] ?? false) ? ' [advisory]' : ''),
            (new Conformance(layoutConfiguration($base, ['sourceRoots' => ['app']])))->violations(Layout::ROW_ACTION_LABEL));
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
}

test('ui.row-action-label flags PHP row action labels that interpolate a value or run long', function () {
    expect(rowLabelFindings(['app/Http/GrantsController.php' => <<<'PHP'
        <?php
        $rows = [['id' => 1, 'actions' => [
            Action::make("Revoke {$grant->name}", '/g/1'),
            new Action('Remove '.$grant->label, '/g/1', 'delete'),
            new Action($member->name, '/m/1'),
            new Action('Resend the invitation email now', '/i/1'),
            new Action('Remove', '/g/1', 'delete', ariaLabel: 'Remove '.$grant->label),
            Action::disabled('Revoke', '/g/1', 'delete', 'Owners cannot be revoked'),
            new \Tey\Kit\Features\Core\Data\Action(label: 'Archive', url: '/o/1'),
            new Action(url: '/o/1', label: $member->name),
            new Action("Charge $5", '/o/1/charge'),
            new Action(__('Remove'), '/g/1'),
            new Action($rule->enabled ? 'Switch off' : 'Switch on', '/r/1', 'post'),
            new Action($e->isFull() ? 'Join the waitlist' : ($e->isFree() ? 'RSVP' : 'Get a ticket'), '/e/1/rsvp', 'post'),
            new Action($on ? 'Off' : 'Resend the invitation email now', '/r/1'),
            new Action($on ? 'Off' : 'Remove '.$grant->label, '/r/1'),
            'submit' => new Action('Book this room for the whole day', '/b', 'post'),
        ]]];

        final class SignViewModel
        {
            public function submit(): ?Action
            {
                return $this->open ? new Action('I agree and sign', '/sign', 'post', key: 'sign') : null;
            }
        }

        $more = ['id' => 2, 'actions' => [new Action('Resend the invitation email again', '/i/1')]];
        PHP]))->toBe([
        'app/Http/GrantsController.php:3 row action label "Revoke {$grant->name}" interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        'app/Http/GrantsController.php:4 row action label \'Remove \'.$grant->label interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        'app/Http/GrantsController.php:5 row action label $member->name interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        "app/Http/GrantsController.php:6 row action label 'Resend the invitation email now' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        'app/Http/GrantsController.php:10 row action label $member->name interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        // A ternary of literals is read arm by arm: a long arm is flagged, a short one is not; an arm with a value still interpolates.
        "app/Http/GrantsController.php:15 row action label 'Resend the invitation email now' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        'app/Http/GrantsController.php:16 row action label $on ? \'Off\' : \'Remove \'.$grant->label interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        // After a submit() method has closed, an Action is a row action again.
        "app/Http/GrantsController.php:28 row action label 'Resend the invitation email again' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
    ]);
});

test('ui.row-action-label leaves form submits, header buttons, links and short verbs in a variable alone', function () {
    expect(rowLabelFindings(['app/Http/OrdersController.php' => <<<'PHP'
        <?php

        final class OrdersController
        {
            public function create(): array
            {
                // A form submit built outside the form.
                $save = new Action('Save the assignment changes', '/assignments', 'post');
                $defaults = new Action("Save {$term} reminder defaults", '/defaults', 'put');

                return ['form' => new FormData(fields: [], submit: $save), 'defaults' => new FormData(fields: [], submit: $defaults)];
            }

            public function show(Order $order): array
            {
                // Page and header buttons, and a header menu item.
                $label = $order->paid ? 'Mark as unpaid again' : 'Mark as paid in full';

                return [
                    'actions' => [
                        'create' => new Action("New {$term} for {$order->customer}", '/orders/create', 'get'),
                        'paid' => new Action($label, '/orders/1/paid', 'post'),
                        'export' => new Action('Export every order to CSV', '/orders/export', 'get', menu: true),
                    ],
                    // Navigation links.
                    'back' => new Action('Back to all of the orders', '/orders', 'get'),
                    'seeAll' => new Action('See all '.$terms.' for '.$order->customer, '/orders', 'get'),
                ];
            }

            public function index(): array
            {
                // Short verbs and tenant terms in row actions: a variable holds a literal, a term is one word.
                $rows = $this->orders->map(function (Order $order): array {
                    $verb = 'Post';
                    $label = $order->paid ? 'Mark unpaid' : 'Mark paid';
                    if ($order->draft) {
                        $label = 'Publish';
                    }

                    return ['id' => $order->id, 'actions' => [
                        new Action($verb, '/orders/1/post', 'post'),
                        new Action($label, '/orders/1/paid', 'post'),
                        new Action("Copy {$term}", '/orders/1/copy', 'post'),
                        new Action('Move '.$terms->plural, '/orders/1/move', 'post'),
                        new Action('Assign {term}', '/orders/1/assign', 'post'),
                    ]];
                });

                return ['table' => new TableData($rows->all())];
            }
        }
        PHP,
        'resources/js/pages/Orders/Show.vue' => <<<'VUE'
        <script setup lang="ts">
        const back = { label: `Back to every ${props.order.customer} order`, url: '/orders', method: 'get' };
        </script>
        <template><Page title="Order" /></template>
        VUE,
        'resources/js/pages/Orders/Index.vue' => <<<'VUE'
        <script setup lang="ts">
        const actions = (row) => [{ key: 'copy', label: `Copy ${terms.singular}`, url: `/o/${row.id}/copy`, method: 'post' }];
        </script>
        <template><DataTable :table="table" /></template>
        VUE]))->toBe([]);
});

test('ui.row-action-label follows a row action through its variable, method and item, and reads a label from its variable', function () {
    expect(rowLabelFindings(['app/Http/GrantsController.php' => <<<'PHP'
        <?php

        final class GrantsController
        {
            public function index(): array
            {
                return ['table' => $this->grants->map(function (Grant $grant): array {
                    $actions = [];
                    $actions[] = new Action('Remove '.$grant->label, '/g/1', 'delete');
                    $label = 'Resend the invitation email';
                    $named = "Revoke {$grant->name}";
                    $actions = [...$actions, new Action($label, '/g/1/resend'), new Action($named, '/g/1', 'delete')];

                    return ['id' => $grant->id, 'actions' => [...$actions, ...$this->rowActions($grant)]];
                })];
            }

            public function slides(): array
            {
                return array_map(fn (Slide $slide): WorkbenchItem => new WorkbenchItem((string) $slide->id, $slide->title, actions: [
                    new Action("Duplicate {$slide->title}", '/s/1/duplicate', 'post'),
                ]), $this->slides);
            }

            private function rowActions(Grant $grant): array
            {
                return [new Action("Archive this {$term} for now", '/g/1/archive', 'post')];
            }
        }
        PHP]))->toBe([
        'app/Http/GrantsController.php:9 row action label \'Remove \'.$grant->label interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        "app/Http/GrantsController.php:12 row action label 'Resend the invitation email' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        'app/Http/GrantsController.php:12 row action label "Revoke {$grant->name}" interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        'app/Http/GrantsController.php:21 row action label "Duplicate {$slide->title}" interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        // A tenant term is one word, so a label over three words with one is still long.
        'app/Http/GrantsController.php:27 row action label "Archive this {$term} for now" is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm',
    ]);
});

test('ui.row-action-label reads a braced tenant term as one word and any other braced value as a value', function () {
    expect(rowLabelFindings(['app/Http/GrantsController.php' => <<<'PHP'
        <?php
        $rows = [['id' => 1, 'actions' => [
            new Action("Copy {$term}", '/g/1/copy', 'post'),
            new Action("Move {$this->terms->singular}", '/g/1/move', 'post'),
            new Action("Assign ${term}", '/g/1/assign', 'post'),
            new Action("Archive this {$terms->plural} now", '/g/1/archive', 'post'),
            new Action("Archive this ${term} now", '/g/1/archive', 'post'),
            new Action("Revoke {$grant->name}", '/g/1', 'delete'),
            new Action("Revoke ${grant}", '/g/1', 'delete'),
        ]]];
        PHP,
        'resources/js/pages/Grants/Index.vue' => <<<'VUE'
        <script setup lang="ts">
        const actions = (row) => [
            { key: 'copy', label: `Copy ${terms.singular}`, url: `/g/${row.id}/copy`, method: 'post' },
            { key: 'archive', label: `Archive every ${terms.plural} now`, url: `/g/${row.id}/archive`, method: 'post' },
            { key: 'revoke', label: `Revoke ${row.name}`, url: `/g/${row.id}`, method: 'delete' },
        ];
        </script>
        <template><DataTable :table="table" /></template>
        VUE]))->toBe([
        'app/Http/GrantsController.php:6 row action label "Archive this {$terms->plural} now" is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm',
        'app/Http/GrantsController.php:7 row action label "Archive this ${term} now" is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm',
        'app/Http/GrantsController.php:8 row action label "Revoke {$grant->name}" interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        'app/Http/GrantsController.php:9 row action label "Revoke ${grant}" interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        'resources/js/pages/Grants/Index.vue:4 row action label `Archive every ${terms.plural} now` is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm',
        'resources/js/pages/Grants/Index.vue:5 row action label `Revoke ${row.name}` interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
    ]);
});

test('ui.row-action-label reads no Action in a heredoc or nowdoc body, and reads on from a closing marker mid-line', function () {
    expect(rowLabelFindings(['app/Http/HelpController.php' => <<<'PHP'
        <?php
        $help = <<<'TXT'
            Don't build rows by hand: ['id' => 1, 'actions' => [new Action('Resend the invitation email now', '/i/1')]]
            TXT;
        $sample = <<<HTML
            <p>{$name}'s row (</p> ['id' => 2, 'actions' => [new Action("Revoke {$grant->name}", '/g/1')]]
            HTML;
        $rows = [['id' => 3, 'actions' => [new Action('Resend the invitation email again', '/i/1')]]];
        $more = [['id' => 4, 'note' => <<<TXT
            A row's note: (
            TXT, 'actions' => [new Action('Resend the invitation email later', '/i/1')]]];
        PHP]))->toBe([
        "app/Http/HelpController.php:8 row action label 'Resend the invitation email again' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        // PHP ends a heredoc at its identifier followed by any non-identifier character, so code after `TXT,` on the same line is read.
        "app/Http/HelpController.php:11 row action label 'Resend the invitation email later' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
    ]);
});

test('ui.row-action-label reads no Action in a backtick shell string', function () {
    expect(rowLabelFindings(['app/Console/ExportRows.php' => <<<'PHP'
        <?php
        $out = `echo ['id' => 1, 'actions' => [new Action('Resend the invitation email now', '/i/1')]]`;
        $rows = [['id' => 3, 'log' => `grep -c \] rows.log`, 'actions' => [new Action('Resend the invitation email again', '/i/1')]]];
        PHP]))->toBe([
        "app/Console/ExportRows.php:3 row action label 'Resend the invitation email again' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
    ]);
});

test('ui.row-action-label reads a label a closure captures with use or an arrow fn sees from the scope around it', function () {
    expect(rowLabelFindings(['app/Http/PostsController.php' => <<<'PHP'
        <?php

        final class PostsController
        {
            public function index(): array
            {
                $label = 'Publish';
                $long = 'Resend the invitation email';
                $draft = function (): void {
                    $label = 'Send this post to every subscriber';
                };

                return $this->posts->map(function (Post $post) use ($label, &$long): array {
                    return ['id' => $post->id, 'actions' => [new Action($label, '/p/1'), new Action($long, '/p/1/resend')]];
                })->all();
            }

            public function uncaptured(): array
            {
                $label = 'Publish';

                return $this->posts->map(function (Post $post): array {
                    return ['id' => $post->id, 'actions' => [new Action($label, '/p/1')]];
                })->all();
            }

            public function arrows(): array
            {
                $label = 'Publish';
                $long = 'Resend the invitation email';

                return [
                    ...$this->posts->map(fn (Post $post): array => ['id' => $post->id, 'actions' => [new Action($label, '/p/1'), new Action($long, '/p/1/resend')]]),
                    ...$this->posts->map(fn (string $label): array => ['id' => 1, 'actions' => [new Action($label, '/p/1')]]),
                ];
            }
        }
        PHP]))->toBe([
        // Captured with use: 'Publish' is a verb, the long label is long; the assignment in the other closure is its own.
        "app/Http/PostsController.php:14 row action label 'Resend the invitation email' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        // Not captured: nothing in reach is assigned to it.
        'app/Http/PostsController.php:23 row action label $label interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        "app/Http/PostsController.php:33 row action label 'Resend the invitation email' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        // An arrow fn parameter shadows the assignment around it.
        'app/Http/PostsController.php:34 row action label $label interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
    ]);
});

test('ui.row-action-label follows a method only to calls that reach its own class, a function to its plain calls and a match to its arms', function () {
    expect(rowLabelFindings(['app/Http/OrdersController.php' => <<<'PHP'
        <?php

        function archiveAction(Order $order): Action
        {
            return new Action("Archive {$order->name}", '/o/1/archive');
        }

        abstract class BaseRows
        {
            protected function restore(Order $order): Action
            {
                return new Action("Restore {$order->name}", '/o/1/restore');
            }
        }

        final class HeaderBar
        {
            public function render(): array
            {
                return ['actions' => [$this->action()]];
            }

            private function action(): Action
            {
                return new Action('Export every order to CSV', '/o/export');
            }
        }

        final class OrderRows extends BaseRows
        {
            public function rows(Order $order): array
            {
                return [['id' => 1, 'actions' => [$this->action(), self::status($order), $this->restore($order), archiveAction($order)]]];
            }

            private function action(): Action
            {
                return new Action('Resend the invitation email', '/o/1/resend');
            }

            private static function status(Order $order): Action
            {
                return match ($order->status) {
                    'draft' => new Action("Publish {$order->name}", '/o/1/publish'),
                    default => new Action('Archive', '/o/1/archive'),
                };
            }
        }
        PHP]))->toBe([
        // A plain function called in a row.
        'app/Http/OrdersController.php:5 row action label "Archive {$order->name}" interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        // Inherited by the row class.
        'app/Http/OrdersController.php:12 row action label "Restore {$order->name}" interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        // HeaderBar::action() at line 25 is called only in the header; OrderRows::action() is the row's.
        "app/Http/OrdersController.php:38 row action label 'Resend the invitation email' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        'app/Http/OrdersController.php:44 row action label "Publish {$order->name}" interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
    ]);
});

test('ui.row-action-label reads past a comment or heredoc around a row action, its key and a submit method', function () {
    expect(rowLabelFindings(['app/Http/InvitesController.php' => <<<'PHP'
        <?php
        $rows = [['id' => 1,
            // The row menu.
            'actions' => [new Action(
                // Don't name the grant here.
                'Resend the invitation email now', '/i/1'),
            ]]];

        function grantRow(Grant $grant): array
        {
            $actions = [];
            // One per grant.
            $actions[] = new Action("Revoke {$grant->name}", '/g/1');

            return ['id' => $grant->id, 'actions' => $actions];
        }

        final class InviteForm
        {
            public function save(): Action
            {
                // Opens no { block
                $css = <<<CSS
                    .x {
                    CSS;

                return new Action('Send the invitation to everyone', '/i', 'post');
            }

            public function rows(): array
            {
                return [['id' => 1, 'actions' => [new Action('Resend the invitation email again', '/i/1')]]];
            }
        }
        PHP]))->toBe([
        "app/Http/InvitesController.php:6 row action label 'Resend the invitation email now' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        'app/Http/InvitesController.php:13 row action label "Revoke {$grant->name}" interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        // save() is a form submit; the method after it is not.
        "app/Http/InvitesController.php:32 row action label 'Resend the invitation email again' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
    ]);
});

test('ui.row-action-label reads a form submit from its key in code, not from a comment or string before a row action', function () {
    expect(rowLabelFindings(['app/Http/InvitesController.php' => <<<'PHP'
        <?php
        $rows = [['id' => 1, 'actions' => [
            // submit:
            new Action('Resend the invitation email now', '/i/1'),
            /* save => */ new Action('Resend the invitation email again', '/i/1'),
            $mode === 'save: draft' ? new Action('Resend the invitation email later', '/i/1') : null,
            // Still form submits: a quoted key past a comment, and a condition holding a comma in a string.
            "submit" /* the form's */ => new Action('Send the invitation to everyone', '/i', 'post'),
            'save' => $mode === 'a, b' ? new Action('Save the invitation for everyone', '/i', 'post') : null,
        ]]];
        PHP]))->toBe([
        "app/Http/InvitesController.php:4 row action label 'Resend the invitation email now' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        "app/Http/InvitesController.php:5 row action label 'Resend the invitation email again' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        "app/Http/InvitesController.php:6 row action label 'Resend the invitation email later' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
    ]);
});

test('ui.row-action-label reads a row key, an id, an Action call, an argument name and a label past a comment', function () {
    expect(rowLabelFindings([
        'app/Http/InvitesController.php' => <<<'PHP'
        <?php
        $rows = [['id' /* the key */ => 1, 'actions' => [new Action('Resend the invitation email now', '/i/1')]]];
        $more = [['id' => 2, 'actions' // the row menu
            => [new Action('Resend the invitation email again', '/i/1')]]];
        $others = [['id' => 3, 'actions' => [new /* the kit's */ Action('Resend the invitation email later', '/i/1')]]];
        $named = [['id' => 4, 'actions' => [new Action(url: '/i/1', label /* the verb */ : 'Resend the invitation email today')]]];

        function grantRow(Grant $grant): array
        {
            $label = // the verb
                'Resend the invitation email first';

            return ['id' => $grant->id, 'actions' => [new Action($label, '/g/1')]];
        }
        PHP,
        'resources/js/pages/People.vue' => <<<'VUE'
        <script setup lang="ts">
        const actions = (row) => [
            { key: 'resend', label: // the verb
                'Resend the invitation email', url: '/p/1/resend', method: 'post' },
            { key: 'toggle', label: row.on ? `Off, now` : 'On', url: '/p/1/toggle', method: 'post' },
        ];
        </script>
        <template><DataTable :table="table" /></template>
        VUE,
        'resources/js/pages/Grants.vue' => <<<'VUE'
        <template>
            <!-- The <script> below builds each row's actions. -->
            <DataTable :table="table" />
        </template>
        <script setup lang="ts">
        const actions = (grant) => [{ label: `Revoke ${grant.name}`, url: '/g/1', method: 'delete' }];
        </script>
        VUE,
    ]))->toBe([
        "app/Http/InvitesController.php:2 row action label 'Resend the invitation email now' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        "app/Http/InvitesController.php:4 row action label 'Resend the invitation email again' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        "app/Http/InvitesController.php:5 row action label 'Resend the invitation email later' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        "app/Http/InvitesController.php:6 row action label 'Resend the invitation email today' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        "app/Http/InvitesController.php:13 row action label 'Resend the invitation email first' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        // A template comment naming `<script>` doesn't start a script block, so its apostrophe doesn't hide the real block's action.
        'resources/js/pages/Grants.vue:6 row action label `Revoke ${grant.name}` interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        // A comment after `label:` is not the label; a comma in a template literal doesn't split a ternary of two short verbs.
        "resources/js/pages/People.vue:3 row action label 'Resend the invitation email' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
    ]);
});

test('ui.row-action-label follows a variable only in its own scope and reads a label built with .= and ??=', function () {
    expect(rowLabelFindings(['app/Http/OrdersController.php' => <<<'PHP'
        <?php

        final class OrdersController
        {
            public function index(): array
            {
                $actions = [new Action('Export every order to CSV', '/o/export')];
                $rows = $this->orders->map(function (Order $order): array {
                    $actions = [new Action('Archive', '/o/1/archive')];

                    return ['id' => $order->id, 'actions' => $actions];
                });

                return ['actions' => $actions, 'rows' => $rows];
            }

            public function rows(Order $order): array
            {
                $label = 'Remove ';
                $label .= $order->name;
                $verb ??= 'Send this order to the printer';

                return [['id' => $order->id, 'actions' => [new Action($label, '/o/1'), new Action($verb, '/o/1/print')]]];
            }
        }
        PHP]))->toBe([
        // The page's $actions at line 7 is not the closure's own $actions.
        'app/Http/OrdersController.php:23 row action label \'Remove \'.$order->name interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        "app/Http/OrdersController.php:23 row action label 'Send this order to the printer' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
    ]);
});

test('ui.row-action-label flags Vue row action labels that interpolate a value or run long and leaves form labels alone', function () {
    expect(layoutFindings(Layout::ROW_ACTION_LABEL, ['pages/People.vue' => <<<'VUE'
        <script setup lang="ts">
        const fields = [{ label: `Name of ${team}`, name: 'name' }];
        const actions = (row) => [
            { key: 'remove', label: `Remove ${row.name}`, url: `/p/${row.id}`, method: 'delete' },
            { key: 'role', label: 'Change role of ' + row.name, url: '/p/1/role', method: 'put' },
            { key: 'open', label: row.name, url: '/p/1', method: 'get' },
            { key: 'resend', label: 'Resend the invitation email', url: '/p/1/resend', method: 'post' },
            { key: 'archive', label: 'Archive', url: '/p/1/archive', method: 'post' },
            { key: 'toggle', label: row.on ? 'Switch off' : 'Switch on', url: '/p/1/toggle', method: 'post' },
            { key: 'join', label: row.full ? 'Join the waitlist now please' : 'Join', url: '/p/1/join', method: 'post' },
        ];
        const props = defineProps<{ submit: { label: string; url: string; method: string; key?: string | null } }>();
        type RowAction = { label: string, url: string, method: string };
        const form = { submit: { label: 'Book this room for the whole day', url: '/b', method: 'post' } };
        </script>
        <template><DataTable :table="table" /></template>
        VUE]))->toBe([
        'resources/js/pages/People.vue:5 row action label `Remove ${row.name}` interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        "resources/js/pages/People.vue:6 row action label 'Change role of ' interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm",
        'resources/js/pages/People.vue:7 row action label row.name interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        "resources/js/pages/People.vue:8 row action label 'Resend the invitation email' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        "resources/js/pages/People.vue:11 row action label 'Join the waitlist now please' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
    ]);
});

test('ui.row-action-label reads a nested confirm and each script block at its own line', function () {
    expect(layoutFindings(Layout::ROW_ACTION_LABEL, ['pages/Grants.vue' => <<<'VUE'
        <template><DataTable :table="table" /></template>
        <script lang="ts">
        export default { name: 'Grants' };
        </script>
        <script setup lang="ts">
        const actions = (grant) => [
            { key: 'remove', label: `Remove ${grant.label}`, confirm: { title: 'Remove it?', description: 'Gone.', label: 'Remove' }, url: '/g/1', method: 'delete' },
        ];
        </script>
        VUE]))->toBe([
        'resources/js/pages/Grants.vue:7 row action label `Remove ${grant.label}` interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
    ]);
});

test('ui.row-action-label reads Vue action objects only where the template renders rows, not where a comment or string names a row component', function () {
    expect(rowLabelFindings([
        'resources/js/pages/Settings.vue' => <<<'VUE'
        <script setup lang="ts">
        // Unlike a <DataTable>, this page lists no rows.
        const hint = '<Workbench> pages list their items';
        const back = { label: `Back to ${props.team.name}`, url: '/t', method: 'get' };
        </script>
        <template>
            <!-- <RowActions :actions="actions" /> -->
            <Page title="Settings" />
        </template>
        VUE,
        'resources/js/pages/Members.vue' => <<<'VUE'
        <script setup lang="ts">
        const actions = [{ label: `Remove ${member.name}`, url: '/m/1', method: 'delete' }];
        </script>
        <template>
            <Page title="Members"><Card><RowActions :actions="actions" /></Card></Page>
        </template>
        VUE,
    ]))->toBe([
        'resources/js/pages/Members.vue:2 row action label `Remove ${member.name}` interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
    ]);
});

test('ui.row-action-label reads no Vue action object in a script comment, string or regex', function () {
    expect(rowLabelFindings(['resources/js/pages/People.vue' => <<<'VUE'
        <script setup lang="ts">
        // const old = [{ label: `Remove ${row.name}`, url: '/p/1', method: 'delete' }];
        /* { label: `Revoke ${row.name}`, url: '/p/1', method: 'delete' } */
        const hint = "{ label: `Copy ${row.name}`, url: '/p/1', method: 'post' }";
        const quote = /['"`]/g;
        const actions = [{ label: `Archive ${row.name}`, url: '/p/1', method: 'post' }];
        </script>
        <template><DataTable :table="table" /></template>
        VUE]))->toBe([
        'resources/js/pages/People.vue:6 row action label `Archive ${row.name}` interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
    ]);
});

test('ui.row-action-label warns on a Vue action past a / the scan can only guess at, rather than skipping it', function () {
    $interpolates = 'interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm';
    expect(rowLabelFindings([
        'resources/js/pages/People.vue' => <<<'VUE'
        <script setup lang="ts">
        const before = [{ label: `Remove ${row.name}`, url: '/p/1', method: 'delete' }];
        const half = width / 2;
        // const old = [{ label: `Revoke ${row.name}`, url: '/p/1', method: 'delete' }];
        function quotes() {
            return /['"`]/g;
        }
        const actions = [
            { label: `Archive ${row.name}`, url: '/p/1', method: 'post' },
            { label: 'Archive', url: '/p/1', method: 'post' },
        ];
        </script>
        <template><DataTable :table="table" /></template>
        VUE,
        'resources/js/pages/Teams.vue' => <<<'VUE'
        <script setup lang="ts">
        if (ready) /['"]/.test(name);
        const actions = [{ label: `Leave ${team.name}`, url: '/t/1', method: 'delete' }];
        </script>
        <template><DataTable :table="table" /></template>
        VUE,
    ]))->toBe([
        "resources/js/pages/People.vue:2 row action label `Remove \${row.name}` {$interpolates}",
        "resources/js/pages/People.vue:9 row action label `Archive \${row.name}` {$interpolates} (read unmasked: the script can't be masked with certainty from line 6, so this only warns) [advisory]",
        "resources/js/pages/Teams.vue:3 row action label `Leave \${team.name}` {$interpolates} (read unmasked: the script can't be masked with certainty from line 2, so this only warns) [advisory]",
    ]);
    expect((new Layout(layoutConfiguration(sys_get_temp_dir())))->quiet("row action label `Leave \${team.name}` {$interpolates} (read unmasked: the script can't be masked with certainty from line 2, so this only warns)"))->toBeTrue();
});

test('ui.row-action-label warns on a Vue action past an interpolation holding a literal or a string read past its line, and reads on where the mask is certain', function () {
    $interpolates = 'interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm';
    expect(rowLabelFindings([
        'resources/js/pages/Plain.vue' => <<<'VUE'
        <script setup lang="ts">
        const title = `${count} of ${total}`;
        const ratio = done / total;
        // const old = [{ label: `Revoke ${row.name}`, url: '/p/1', method: 'delete' }];
        const actions = [{ label: `Remove ${row.name}`, url: '/p/1', method: 'delete' }];
        </script>
        <template><DataTable :table="table" /></template>
        VUE,
        'resources/js/pages/Braces.vue' => <<<'VUE'
        <script setup lang="ts">
        const brace = `${open ? '{' : ''}`;
        const actions = [{ label: `Revoke ${row.name}`, url: '/p/1', method: 'delete' }];
        </script>
        <template><DataTable :table="table" /></template>
        VUE,
        'resources/js/pages/Render.vue' => <<<'VUE'
        <script setup lang="tsx">
        const Hint = () => <p>Don't reload</p>;
        const actions = [{ label: `Retry ${row.name}`, url: '/p/1', method: 'post' }];
        </script>
        <template><DataTable :table="table" /></template>
        VUE,
    ]))->toBe([
        "resources/js/pages/Braces.vue:3 row action label `Revoke \${row.name}` {$interpolates} (read unmasked: the script can't be masked with certainty from line 2, so this only warns) [advisory]",
        "resources/js/pages/Plain.vue:5 row action label `Remove \${row.name}` {$interpolates}",
        "resources/js/pages/Render.vue:3 row action label `Retry \${row.name}` {$interpolates} (read unmasked: the script can't be masked with certainty from line 2, so this only warns) [advisory]",
    ]);
});
