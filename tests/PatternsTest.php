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

    foreach (glob(patternSources().'/*.yaml') as $file) {
        copy($file, $directory.'/'.basename($file));
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
        ->and(array_column($root->options, 'next'))->toBe(Compiler::BRANCHES);
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
    'bad status' => ['status', 'done', 'status must be ready, draft or gap'],
]);

test('step mode asks one question at a time and ends on a leaf with its make command', function () {
    $lookup = new Lookup(TreeCatalog::shipped());

    expect($lookup->step())->toMatchArray(['type' => 'question', 'id' => 'root', 'question' => 'What are you building?']);
    expect($lookup->step('root', '4'))->toMatchArray(['type' => 'question', 'id' => 'settings']);
    expect($lookup->step('settings', '2'))->toMatchArray(['type' => 'question', 'id' => 'settings.section']);
    expect($lookup->step('settings.section', 'settings.person'))->toMatchArray(['type' => 'question', 'id' => 'settings.person']);
    expect($lookup->step('settings.person', 'On/off preferences'))
        ->toMatchArray(['type' => 'leaf', 'id' => 'settings.toggles', 'make' => 'standards:make settings-section --flavor=toggles']);
    expect(fn () => $lookup->step('settings', 'nonsense'))->toThrow(InvalidArgumentException::class);
});

test('intent mode returns candidate leaves and at most two disambiguating questions', function () {
    $lookup = new Lookup(TreeCatalog::shipped());
    $result = $lookup->intent('a settings page with on off toggle switches');

    expect($result['mode'])->toBe('intent')
        ->and($result['candidates'][0]['id'])->toBe('settings.toggles')
        ->and(count($result['questions']))->toBeLessThanOrEqual(2);

    $byPath = $lookup->intent('resources/js/pages/settings/Alerts/Page.vue');
    expect($byPath['mode'])->toBe('path')
        ->and(array_column($byPath['candidates'], 'id'))->toBe(['settings.form', 'settings.toggles'])
        ->and(array_column($byPath['questions'], 'id'))->toBe(['settings.person']);

    expect($lookup->intent('quantum flux capacitor')['questions'][0]['id'])->toBe('root');
});

test('gap leaves name the nearest sibling', function () {
    $lookup = new Lookup(TreeCatalog::shipped());
    $gaps = $lookup->gaps();

    expect(array_column($gaps, 'id'))->toContain('mcp.queued', 'settings.workspace');

    foreach ($gaps as $gap) {
        expect($gap['message'])->toMatch('/^No recipe yet\. Build to the nearest sibling [a-z.-]+ and flag it\.$/');
    }

    expect($lookup->intent('mcp.queued')['message'])->toBe('No recipe yet. Build to the nearest sibling mcp.read and flag it.');
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

    expect((new Lookup($catalog))->intent('mcp.write'))
        ->toMatchArray(['preferred_make' => 'standards:make capability-tool --flavor=write <Name>', 'preferred_make_source' => 'tey/standards']);
    expect((new Lookup($catalog, recipes: true))->intent('mcp.write'))
        ->toMatchArray(['preferred_make' => 'mod:butter/mcp-parity <Name>', 'preferred_make_source' => 'bakery (butter/bakery)']);
    expect((new Lookup($catalog, recipes: true))->intent('mcp.read')['preferred_make_source'])->toBe('tey/standards');

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
    $weights = array_column($gaps, 'weight');
    $sorted = $weights;
    rsort($sorted);

    expect($weights)->toBe($sorted)
        ->and($gaps[0]['id'])->toBe('ui-page.workbench')
        ->and($gaps[0]['weight'])->toBe(5 * 25)
        ->and(end($gaps)['weight'])->toBe(0);

    foreach (TreeCatalog::shipped()->leaves() as $leaf) {
        if ($leaf->evidence !== null) {
            expect(array_keys($leaf->evidence))->toBe(['apps', 'count', 'prs'])
                ->and(array_filter($leaf->evidence, 'is_int'))->toBe($leaf->evidence);
        }
    }

    $directory = mutatedPatterns('settings', function (array $source): array {
        $source['nodes'][nodeAt($source, 'settings.house')]['evidence'] = ['apps' => ['a client app'], 'count' => 1, 'prs' => 1];

        return $source;
    });
    expect(fn () => Compiler::compile($directory))->toThrow(InvalidArgumentException::class, 'non-negative counts');
});

test('public pattern sources name no client apps and cite no change references', function () {
    foreach ([...glob(patternSources().'/*.yaml'), patternSources().'/catalog.json'] as $file) {
        expect(file_get_contents($file))->not->toMatch('/#\d{1,5}\b|cookbook/i');
    }
});
