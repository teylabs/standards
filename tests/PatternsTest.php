<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Tey\Standards\Enforcement\CheckReport;
use Tey\Standards\Enforcement\Maker;
use Tey\Standards\Patterns\Branch;
use Tey\Standards\Patterns\Compiler;
use Tey\Standards\Patterns\Leaf;
use Tey\Standards\Patterns\Lookup;
use Tey\Standards\Patterns\TreeCatalog;

function patternSources(): string
{
    return dirname(__DIR__).'/resources/patterns';
}

/** Copies the YAML sources to a temporary directory and lets the callback change one branch. */
function mutatedPatterns(string $branch, callable $change): string
{
    $directory = sys_get_temp_dir().'/standards-patterns-'.bin2hex(random_bytes(6));
    mkdir($directory);

    foreach (glob(patternSources().'/*.{yaml,json}', GLOB_BRACE) as $file) {
        copy($file, $directory.'/'.basename($file));
    }

    mkdir($directory.'/features');

    foreach (glob(patternSources().'/features/*.yaml') as $file) {
        copy($file, $directory.'/features/'.basename($file));
    }

    $source = Yaml::parseFile($directory.'/'.$branch.'.yaml');
    file_put_contents($directory.'/'.$branch.'.yaml', Yaml::dump($change($source), 10));

    return $directory;
}

/** @param array{nodes: list<array<string, mixed>>} $source */
function nodeAt(array $source, string $id): int
{
    return (int) array_search($id, array_column($source['nodes'], 'id'), true);
}

/** A temporary copy of the fixture app whose classes autoload. */
function fixtureApp(): string
{
    $base = sys_get_temp_dir().'/standards-app-'.bin2hex(random_bytes(6));
    (new Filesystem)->copyDirectory(dirname(__DIR__).'/fixtures/App', $base);
    require_once $base.'/tests/Support/framework.php';
    spl_autoload_register(function (string $class) use ($base): void {
        $file = $base.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';

        if (str_starts_with($class, 'App\\') && is_file($file)) {
            require_once $file;
        }
    });

    return $base;
}

test('the shipped catalog.json is the compiled YAML', function () {
    $compiled = json_encode(Compiler::compile(patternSources()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;

    expect(file_get_contents(patternSources().'/catalog.json'))->toBe($compiled, 'Run php bin/standards patterns:compile.');
});

test('the tree starts at "What are you building?" with the eight branches', function () {
    $root = TreeCatalog::shipped()->root();

    expect($root->question)->toBe('What are you building?')
        ->and(array_column($root->options, 'next'))->toBe([...Compiler::BRANCHES, 'byo'])
        ->and($root->options[array_key_last($root->options)]['answer'])->toBe('None of these');
});

test('every next resolves, branches below the root have 2–5 options and every node is reachable exactly once', function () {
    $catalog = TreeCatalog::shipped();
    $reached = [];
    $walk = function (string $id) use (&$walk, &$reached, $catalog): void {
        expect($reached)->not->toHaveKey($id);
        $reached[$id] = true;
        $node = $catalog->node($id);
        expect($node)->not->toBeNull();

        // The root lists the eight agreed top-level branches; the 2–5 rule applies below it.
        if ($node instanceof Branch && $id !== 'root') {
            expect(count($node->options))->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(5);

        }

        if ($node instanceof Branch) {
            foreach ($node->options as $option) {
                $walk($option['next']);
            }
        }
    };
    $walk('root');
    $json = json_decode(file_get_contents(patternSources().'/catalog.json'), true);

    expect(array_keys($reached))->toEqualCanonicalizing(array_keys($json['nodes']));
});

test('ui-page, mcp and settings are populated with ready leaves; the other branches are draft or gap stubs', function () {
    $catalog = TreeCatalog::shipped();

    foreach (Compiler::BRANCHES as $branch) {
        $leaves = array_filter($catalog->leaves(), fn (Leaf $leaf): bool => str_starts_with($leaf->id, $branch.'.'));
        $ready = array_filter($leaves, fn (Leaf $leaf): bool => $leaf->status === 'ready');

        expect($leaves)->not->toBeEmpty();
        in_array($branch, ['ui-page', 'mcp', 'settings'], true)
            ? expect(count($ready))->toBeGreaterThanOrEqual(2)
            : expect($ready)->toBe([]);
    }
});

test('the compiler rejects an unresolved next', function () {
    $directory = mutatedPatterns('mcp', function (array $source): array {
        $source['nodes'][nodeAt($source, 'mcp.tool')]['options'][0]['next'] = 'mcp.missing';

        return $source;
    });

    expect(fn () => Compiler::compile($directory))->toThrow(InvalidArgumentException::class, 'points to missing node mcp.missing');
});

test('the compiler rejects an orphan', function () {
    $directory = mutatedPatterns('mcp', function (array $source): array {
        $source['nodes'][] = ['id' => 'mcp.orphan', 'recipe' => 'x', 'summary' => 'x', 'kit' => [], 'checks' => [], 'paths' => [], 'keywords' => [], 'status' => 'gap'];

        return $source;
    });

    expect(fn () => Compiler::compile($directory))->toThrow(InvalidArgumentException::class, 'Orphaned pattern nodes: mcp.orphan');
});

test('the compiler rejects a cycle', function () {
    $directory = mutatedPatterns('ui-page', function (array $source): array {
        $source['nodes'][nodeAt($source, 'ui-page.list')]['options'][0]['next'] = 'ui-page';

        return $source;
    });

    expect(fn () => Compiler::compile($directory))->toThrow(InvalidArgumentException::class, 'cycle through ui-page');
});

test('the compiler rejects a ready leaf without make, an unknown generator and an unknown check', function (string $field, mixed $value, string $message) {
    $directory = mutatedPatterns('settings', function (array $source) use ($field, $value): array {
        $at = nodeAt($source, 'settings.form');

        if ($value === null) {
            unset($source['nodes'][$at][$field]);
        } else {
            $source['nodes'][$at][$field] = $value;
        }

        return $source;
    });

    expect(fn () => Compiler::compile($directory))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'missing make' => ['make', null, 'Ready leaf settings.form needs a make command'],
    'unknown unit' => ['make', 'standards:make widget --flavor=form', 'known unit and flavor'],
    'unknown check' => ['checks', ['nope'], 'unknown check [nope]'],
    'bad status' => ['status', 'done', 'status must be ready, draft, gap or byo'],
]);

test('answers walk from the root question to a leaf with its make command, contract 1', function () {
    $lookup = new Lookup(TreeCatalog::shipped());

    expect($lookup->find(['answers' => []]))->toMatchArray(['contract' => 1, 'type' => 'question', 'id' => 'root', 'question' => 'What are you building?', 'path' => []]);
    expect($lookup->find(['answers' => ['4', '2']]))->toMatchArray(['type' => 'question', 'id' => 'settings.section']);
    expect($lookup->find(['answers' => ['4', '2', 'settings.person', 'On/off preferences']]))
        ->toMatchArray(['contract' => 1, 'type' => 'leaf', 'id' => 'settings.toggles', 'make' => 'standards:make settings-section --flavor=toggles', 'demand' => null, 'conventions' => null])
        ->and($lookup->find(['answers' => ['4', '2', '1', '2']])['path'])->toBe(['A settings screen', 'A per-tenant or per-person setting', "The signed-in person's own", 'On/off preferences']);
    expect($lookup->find(['answers' => ['4', 'nonsense']]))->toMatchArray(['contract' => 1, 'type' => 'error']);
    expect($lookup->recipe('nope'))->toMatchArray(['contract' => 1, 'type' => 'error']);
});

test('an intent matches a feature plan first, then unit leaves; a path matches unit leaves', function () {
    $lookup = new Lookup(TreeCatalog::shipped());

    $plan = $lookup->find(['intent' => 'manage records for invoices with bulk actions']);
    expect($plan)->toMatchArray(['contract' => 1, 'type' => 'plan'])
        ->and($plan['feature']['id'])->toBe('manage-records')
        ->and(array_column($plan['units'], 'role'))->toContain('list', 'write', 'bulk actions')
        ->and(array_column($plan['questions'], 'id'))->toBe(['bulk', 'mcp', 'notify']);

    $unit = $lookup->find(['intent' => 'a dropzone upload']);
    expect($unit['type'] === 'leaf' ? [$unit['id']] : array_column($unit['candidates'], 'id'))->toContain('ui-page.upload');
    expect($lookup->find(['intent' => 'ui-page.upload']))->toMatchArray(['type' => 'leaf', 'id' => 'ui-page.upload']);

    $byPath = $lookup->find(['path' => 'resources/js/pages/settings/Alerts/Page.vue']);
    expect($byPath['type'])->toBe('question')
        ->and(array_column($byPath['candidates'], 'id'))->toBe(['settings.form', 'settings.toggles'])
        ->and($byPath['id'])->toBe('settings.person');

    expect($lookup->find(['intent' => 'quantum flux capacitor'])['id'])->toBe('root');
});

test('every feature plan lists its units with recipes, and units without one carry their nearest recipes', function () {
    $lookup = new Lookup(TreeCatalog::shipped());
    $features = TreeCatalog::shipped()->features();

    expect(count($features))->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(8);

    foreach ($features as $feature) {
        $plan = $lookup->plan($feature);
        expect($plan['units'])->not->toBeEmpty();

        foreach ($plan['units'] as $unit) {
            expect($unit)->toHaveKeys(['leaf', 'recipe', 'make', 'role']);

            if (in_array($unit['status'], ['gap', 'byo'], true)) {
                expect($unit['siblings'])->not->toBeEmpty()->and($unit['message'])->not->toBeNull();
            }
        }
    }

    expect($lookup->find(['intent' => 'document-send'])['feature']['id'])->toBe('document-send');
});

test('the feature validator rejects unknown units and conditions', function (string $file, Closure $change, string $message) {
    $directory = mutatedPatterns('mcp', fn (array $source): array => $source);
    $feature = Yaml::parseFile($directory.'/features/'.$file);
    file_put_contents($directory.'/features/'.$file, Yaml::dump($change($feature), 10));

    expect(fn () => Compiler::compile($directory))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'branch, not a leaf' => ['manage-records.yaml', fn (array $f): array => [...$f, 'composition' => [['unit_leaf_id' => 'ui-page.list', 'role' => 'list']]], 'not a unit leaf'],
    'unknown leaf' => ['manage-records.yaml', fn (array $f): array => [...$f, 'composition' => [['unit_leaf_id' => 'ui-page.nope', 'role' => 'list']]], 'not a unit leaf'],
    'unknown condition' => ['manage-records.yaml', fn (array $f): array => [...$f, 'composition' => [['unit_leaf_id' => 'ui-page.index', 'role' => 'list', 'when' => 'colour=red']]], 'must name one of its questions'],
    'empty composition' => ['manage-records.yaml', fn (array $f): array => [...$f, 'composition' => []], 'non-empty composition'],
]);

test('every branch reaches a build-your-own exit, and byo leaves return siblings, conventions, a note and a demand record', function () {
    $directory = mutatedPatterns('mcp', function (array $source): array {
        $at = nodeAt($source, 'mcp.tool');
        $source['nodes'][$at]['options'] = array_values(array_filter($source['nodes'][$at]['options'], fn (array $o): bool => $o['next'] !== 'mcp.tool.byo'));
        $source['nodes'] = array_values(array_filter($source['nodes'], fn (array $n): bool => $n['id'] !== 'mcp.tool.byo'));

        return $source;
    });
    expect(fn () => Compiler::compile($directory))->toThrow(InvalidArgumentException::class, 'Branch mcp.tool has no reachable build-your-own');

    $lookup = new Lookup(TreeCatalog::shipped(), now: new DateTimeImmutable('2026-10-27T12:00:00Z'));
    $byo = $lookup->find(['answers' => ['3', '1', 'None of these']]);

    expect($byo)->toMatchArray(['type' => 'leaf', 'id' => 'mcp.tool.byo', 'status' => 'byo'])
        ->and($byo['siblings'])->toContain('mcp.read')
        ->and($byo['conventions']['rules'])->toContain('55', 'actions')
        ->and($byo['message'])->toStartWith('No recipe fits. Proceed')
        ->and($byo['demand'])->toBe([
            'answers' => ['A tool an AI assistant calls over MCP', 'A tool over an app operation', 'None of these'],
            'criteria' => ['assistant', 'mcp', 'operation', 'tool'],
            'unit_type' => 'mcp',
            'ts' => '2026-10-27T12:00:00Z',
        ]);

    expect($lookup->find(['answers' => ['None of these']]))->toMatchArray(['id' => 'byo', 'status' => 'byo']);
});

test('gap leaves name the nearest sibling', function () {
    $lookup = new Lookup(TreeCatalog::shipped());
    $gaps = $lookup->gaps();

    expect(array_column($gaps, 'id'))->toContain('mcp.queued', 'settings.workspace');

    foreach ($gaps as $gap) {
        expect($gap['message'])->toMatch('/^No recipe yet\. Build to the nearest sibling [a-z.-]+ and flag it\.$/');
    }

    expect($lookup->recipe('mcp.queued')['message'])->toBe('No recipe yet. Build to the nearest sibling mcp.read and flag it.')
        ->and($lookup->recipe('mcp.queued')['demand']['unit_type'])->toBe('mcp');
});

test('every ready leaf\'s make command emits code that passes standards:check in a fixture app', function (string $id) {
    $catalog = TreeCatalog::shipped();
    $leaf = $catalog->node($id);
    assert($leaf instanceof Leaf);
    preg_match('/^standards:make ([a-z-]+) --flavor=([a-z-]+)$/', (string) $leaf->make, $make);
    $base = fixtureApp();

    try {
        $files = (new Maker($base, $catalog))->make($make[1], 'Ledger'.ucfirst(str_replace(['.', '-'], '', $id)), $make[2]);
        $report = CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-27'), $catalog);

        expect($files)->not->toBeEmpty()
            ->and($report['violations'])->toBe([])
            ->and($report['exit_code'])->toBe(0);

        foreach ($files as $file) {
            expect(file_get_contents($base.'/'.$file))->toContain('standards-pattern: ');
        }
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
})->with(fn (): array => array_values(array_map(fn (Leaf $leaf): string => $leaf->id, array_filter(TreeCatalog::shipped()->leaves(), fn (Leaf $leaf): bool => $leaf->status === 'ready'))));

test('the fixture app alone passes, and a hand-rolled violation fails the same check', function () {
    $base = fixtureApp();

    try {
        expect(CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-27'), TreeCatalog::shipped())['violations'])->toBe([]);

        file_put_contents($base.'/resources/js/pages/Broken.vue', "<script setup>\nimport x from 'left-pad';\n</script>\n");
        $report = CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-27'), TreeCatalog::shipped());

        expect(array_column($report['violations'], 'rule'))->toContain('js-imports')
            ->and($report['exit_code'])->toBe(1);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('make refuses to overwrite and rejects unknown units', function () {
    $base = fixtureApp();

    try {
        $maker = new Maker($base, TreeCatalog::shipped());
        $maker->make('action', 'Archive', 'plain');

        expect(fn () => $maker->make('action', 'Archive', 'plain'))->toThrow(InvalidArgumentException::class, 'never overwrites')
            ->and(fn () => $maker->make('widget', 'Archive', 'plain'))->toThrow(InvalidArgumentException::class, 'Unknown unit');
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('recipe_make is optional, validated, and preferred only when the recipe package is installed', function () {
    $catalog = TreeCatalog::shipped();
    $leaf = $catalog->node('mcp.write');

    expect($leaf)->toBeInstanceOf(Leaf::class)
        ->and($leaf->recipe)->toBe('mcp-parity')
        ->and($leaf->make)->toBe('standards:make capability-tool --flavor=write')
        ->and($leaf->recipeMake)->toBe('mod:butter/mcp-parity {Name}');

    expect((new Lookup($catalog))->recipe('mcp.write'))
        ->toMatchArray(['preferred_make' => 'standards:make capability-tool --flavor=write <Name>', 'preferred_make_source' => 'tey/standards']);
    expect((new Lookup($catalog, recipes: true))->recipe('mcp.write'))
        ->toMatchArray(['preferred_make' => 'mod:butter/mcp-parity <Name>', 'preferred_make_source' => 'butter/bakery']);
    expect((new Lookup($catalog, recipes: true))->recipe('mcp.read')['preferred_make_source'])->toBe('tey/standards');
    expect($catalog->node('data-actions.document'))->toMatchObject(['recipe' => 'pdf-reports', 'recipeMake' => 'mod:butter/pdf-reports {Name}', 'status' => 'gap']);

    $directory = mutatedPatterns('settings', function (array $source): array {
        $source['nodes'][nodeAt($source, 'settings.form')]['recipe_make'] = 'butter mcp-parity';

        return $source;
    });
    expect(fn () => Compiler::compile($directory))->toThrow(InvalidArgumentException::class, 'recipe_make must look like');

    $directory = mutatedPatterns('settings', function (array $source): array {
        $source['nodes'][nodeAt($source, 'settings.workspace')]['recipe_make'] = 'mod:butter/workspace-settings {Name}';

        return $source;
    });
    expect(Compiler::compile($directory)['nodes']['settings.workspace']['recipe_make'])->toBe('mod:butter/workspace-settings {Name}');
});

test('gap leaves carry count-only evidence and --gaps sorts them by apps × count', function () {
    $gaps = (new Lookup(TreeCatalog::shipped()))->gaps();
    $weights = array_map(fn (array $gap): int => TreeCatalog::shipped()->node($gap['id'])->weight(), $gaps);
    $gaps = array_map(fn (array $gap, int $weight): array => [...$gap, 'weight' => $weight], $gaps, $weights);
    $sorted = $weights;
    rsort($sorted);

    expect($weights)->toBe($sorted)
        ->and($gaps[0]['id'])->toBe('ui-page.crud-resource')
        ->and($gaps[0]['weight'])->toBe(7 * 15 + 33 * 312)
        ->and(end($gaps)['weight'])->toBe(0);

    $imports = array_values(array_filter($gaps, fn (array $gap): bool => ($gap['evidence']['apps'] ?? 1) === 0));
    expect($imports[0]['id'])->toBe('data-actions.file-import');

    foreach (TreeCatalog::shipped()->leaves() as $leaf) {
        if ($leaf->evidence !== null) {
            expect(array_slice(array_keys($leaf->evidence), 0, 3))->toBe(['apps', 'count', 'prs'])
                ->and(array_filter($leaf->evidence, 'is_int'))->toBe($leaf->evidence);
        }
    }

    $directory = mutatedPatterns('settings', function (array $source): array {
        $source['nodes'][nodeAt($source, 'settings.house')]['evidence'] = ['apps' => ['a client app'], 'count' => 1, 'prs' => 1];

        return $source;
    });
    expect(fn () => Compiler::compile($directory))->toThrow(InvalidArgumentException::class, 'all non-negative counts');
});

test('public pattern sources name no client apps and cite no change references', function () {
    foreach ([...glob(patternSources().'/*.yaml'), patternSources().'/catalog.json'] as $file) {
        expect(file_get_contents($file))->not->toMatch('/#\d{1,5}\b|cookbook/i');
    }
});

test('components name only what the kit exports, and recipe() returns them', function () {
    $exports = json_decode(file_get_contents(patternSources().'/kit-exports.json'), true)['components'];
    $recipe = (new Lookup(TreeCatalog::shipped()))->recipe('ui-page.index');

    expect(array_column($recipe['components'], 'kit_component'))->toContain('DataTable', 'Toolbar', 'RowActions')
        ->and(array_keys($recipe['components'][0]))->toBe(['name', 'kit_component', 'use', 'props_hint']);

    foreach (glob(dirname(__DIR__).'/resources/stubs/*.vue.stub') as $stub) {
        preg_match_all("/import \{([^}]*)\} from '@kit\/ui'/", file_get_contents($stub), $imports);

        foreach (array_map('trim', explode(',', implode(',', $imports[1]))) as $name) {
            expect($exports)->toContain($name);
        }
    }

    $directory = mutatedPatterns('ui-page', function (array $source): array {
        $source['nodes'][nodeAt($source, 'ui-page.index')]['components'][] = ['name' => 'toolbar', 'kit_component' => 'IndexToolbar', 'use' => 'x', 'props_hint' => 'x'];

        return $source;
    });
    expect(fn () => Compiler::compile($directory))->toThrow(InvalidArgumentException::class, 'component [IndexToolbar] is not exported by the kit');
});
