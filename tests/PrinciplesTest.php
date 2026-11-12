<?php

declare(strict_types=1);

use Tey\Standards\Conformance;
use Tey\Standards\Patterns\Principles;
use Tey\Standards\Patterns\TreeCatalog;

function principlesDirectory(): string
{
    return dirname(__DIR__).'/resources/patterns';
}

/** A patterns directory holding only the given principles.md, for the compiler's refusals. */
function principlesFixture(string $markdown): string
{
    $directory = sys_get_temp_dir().'/principles-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/principles.md', $markdown);

    return $directory;
}

const PRINCIPLES_HEADER = "# Principles catalog\n\nVersion: 2026-11-05\n\n## Recipes\n\n| id | source | rule | how to check | applies-to |\n|---|---|---|---|---|\n";

test('the shipped principles.json is compiled from principles.md', function () {
    expect(file_get_contents(principlesDirectory().'/principles.json'))->toBe(Principles::json(principlesDirectory()), 'Run php bin/standards principles:compile.');
});

test('every principle id is unique', function () {
    $ids = array_column(json_decode((string) file_get_contents(principlesDirectory().'/principles.json'), true, flags: JSON_THROW_ON_ERROR)['patterns'], 'id');

    expect($ids)->not->toBeEmpty()->and(array_unique($ids))->toBe($ids);
});

test('the compiler refuses a repeated id, a short row and a missing version', function () {
    $row = "| VM-01 | recipe | a rule | a check | page |\n";

    expect(fn () => Principles::compile(principlesFixture(PRINCIPLES_HEADER.$row.$row)))->toThrow(InvalidArgumentException::class, 'repeats the id VM-01')
        ->and(fn () => Principles::compile(principlesFixture(PRINCIPLES_HEADER."| VM-01 | recipe | a rule |\n")))->toThrow(InvalidArgumentException::class, 'has 3 cells')
        ->and(fn () => Principles::compile(principlesFixture(str_replace("Version: 2026-11-05\n", '', PRINCIPLES_HEADER).$row)))->toThrow(InvalidArgumentException::class, 'no "Version');
});

test('every enforced_by names a standards rule, and mode follows it', function () {
    $patterns = json_decode((string) file_get_contents(principlesDirectory().'/principles.json'), true, flags: JSON_THROW_ON_ERROR)['patterns'];

    foreach ($patterns as $pattern) {
        expect(in_array($pattern['mode'], ['enforced', 'advisory', 'review'], true))->toBeTrue($pattern['id'].': mode '.$pattern['mode']);
        if ($pattern['enforced_by'] === null) {
            expect($pattern['mode'])->toBe('review', $pattern['id'].': no rule, so review');

            continue;
        }
        expect(array_key_exists($pattern['enforced_by'], Conformance::TESTS))->toBeTrue($pattern['id'].': no rule '.$pattern['enforced_by'])
            ->and($pattern['mode'])->toBe(Principles::mode($pattern['enforced_by']), $pattern['id']);
    }
    expect(fn () => Principles::mode('no.such-rule'))->toThrow(InvalidArgumentException::class, 'No standards rule is named no.such-rule');
});

test('every tree_leaf is a leaf of the shipped decision tree', function () {
    $leaves = array_map(fn ($leaf): string => $leaf->id, TreeCatalog::shipped()->leaves());
    $patterns = json_decode((string) file_get_contents(principlesDirectory().'/principles.json'), true, flags: JSON_THROW_ON_ERROR)['patterns'];

    foreach (array_filter($patterns, fn (array $pattern): bool => $pattern['tree_leaf'] !== null) as $pattern) {
        expect(in_array($pattern['tree_leaf'], $leaves, true))->toBeTrue($pattern['id'].': no leaf '.$pattern['tree_leaf']);
    }
});

test('rows map from their ids and text, never from a bare word', function () {
    $directory = principlesFixture(PRINCIPLES_HEADER
        ."| CONF-20 | CONFORMANCE rule 20 | numbered rule | audit | see rule |\n"
        ."| VM-31 | recipe | pages stay small | rule `vm.page-size` | page |\n"
        ."| VM-32 | recipe | send `mail` through notifications | grep | mail |\n"
        ."| STD-ui-page.index | standards patterns | the index leaf | checks | ui-page |\n");
    $rows = array_column(Principles::compile($directory)['patterns'], null, 'id');

    expect($rows['CONF-20']['enforced_by'])->toBe('20')->and($rows['CONF-20']['mode'])->toBe('enforced')
        ->and($rows['VM-31']['enforced_by'])->toBe('vm.page-size')->and($rows['VM-31']['mode'])->toBe('advisory')
        ->and($rows['VM-32']['enforced_by'])->toBeNull()->and($rows['VM-32']['mode'])->toBe('review')
        ->and($rows['STD-ui-page.index']['tree_leaf'])->toBe('ui-page.index')->and($rows['STD-ui-page.index']['mode'])->toBe('enforced')
        ->and($rows['VM-31']['applies_to'])->toBe(['page'])
        ->and($rows['VM-31']['tree_leaf'])->toBe('ui-page.show')->and($rows['CONF-20']['tree_leaf'])->toBeNull();
});

test('VM rows map to the form, index or show leaf', function () {
    $rows = array_column(json_decode((string) file_get_contents(principlesDirectory().'/principles.json'), true, flags: JSON_THROW_ON_ERROR)['patterns'], null, 'id');
    $vm = array_filter($rows, fn (array $row): bool => str_starts_with($row['id'], 'VM-'));

    expect($rows['VM-04']['tree_leaf'])->toBe('ui-page.form')->and($rows['VM-08']['tree_leaf'])->toBe('ui-page.index')
        ->and(array_unique(array_column(array_diff_key($vm, ['VM-04' => 1, 'VM-08' => 1]), 'tree_leaf')))->toBe(['ui-page.show']);
});
