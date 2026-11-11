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
        "resources/js/pages/Settings.vue:4 hand-rolled PageSection (a section with an h2 directly in Page): use the kit's PageSection [advisory]",
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

test('ui.row-action-label flags PHP action labels that interpolate a value or run long', function () {
    $base = sys_get_temp_dir().'/standards-row-label-'.bin2hex(random_bytes(6));
    mkdir($base.'/app/Http', 0777, true);
    file_put_contents($base.'/app/Http/GrantsController.php', <<<'PHP'
        <?php
        $a = Action::make("Revoke {$grant->name}", '/g/1');
        $b = new Action('Remove '.$grant->label, '/g/1', 'delete');
        $c = new Action($member->name, '/m/1');
        $d = new Action('Resend the invitation email now', '/i/1');
        $e = new Action('Remove', '/g/1', 'delete', ariaLabel: 'Remove '.$grant->label);
        $f = Action::disabled('Revoke', '/g/1', 'delete', 'Owners cannot be revoked');
        $g = new \Tey\Kit\Features\Core\Data\Action(label: 'Archive', url: '/o/1');
        $h = new Action(url: '/o/1', label: $member->name);
        $i = new Action("Charge $5", '/o/1/charge');
        $j = new Action(__('Remove'), '/g/1');
        $k = new Action($rule->enabled ? 'Switch off' : 'Switch on', '/r/1', 'post');
        $l = [new Action($e->isFull() ? 'Join the waitlist' : ($e->isFree() ? 'RSVP' : 'Get a ticket'), '/e/1/rsvp', 'post')];
        $m = new Action($on ? 'Off' : 'Resend the invitation email now', '/r/1');
        $n = new Action($on ? 'Off' : 'Remove '.$grant->label, '/r/1');
        $o = ['submit' => new Action('Book this room for the whole day', '/b', 'post')];

        final class SignViewModel
        {
            public function submit(): ?Action
            {
                return $this->open ? new Action('I agree and sign', '/sign', 'post', key: 'sign') : null;
            }
        }

        $p = new Action('Resend the invitation email again', '/i/1');
        PHP);

    try {
        $findings = array_map(fn (array $v): string => $v['target'].':'.$v['line'].' '.$v['check'],
            (new Conformance(layoutConfiguration($base, ['sourceRoots' => ['app']])))->violations(Layout::ROW_ACTION_LABEL));
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }

    expect($findings)->toBe([
        'app/Http/GrantsController.php:2 row action label "Revoke {$grant->name}" interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        'app/Http/GrantsController.php:3 row action label \'Remove \'.$grant->label interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        'app/Http/GrantsController.php:4 row action label $member->name interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        "app/Http/GrantsController.php:5 row action label 'Resend the invitation email now' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        'app/Http/GrantsController.php:9 row action label $member->name interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        // A ternary of literals is read arm by arm: a long arm is flagged, a short one is not; an arm with a value still interpolates.
        "app/Http/GrantsController.php:14 row action label 'Resend the invitation email now' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
        'app/Http/GrantsController.php:15 row action label $on ? \'Off\' : \'Remove \'.$grant->label interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm',
        // After a submit() method has closed, an Action is a row action again.
        "app/Http/GrantsController.php:26 row action label 'Resend the invitation email again' is over 24 characters or 3 words: RowActions clamps it; keep the label a verb and put the record in the confirm",
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
