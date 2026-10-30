<?php

declare(strict_types=1);

use Tey\Standards\Adoption\AdoptionError;
use Tey\Standards\Adoption\App;
use Tey\Standards\Adoption\Inventory;
use Tey\Standards\Adoption\Scanner;

/*
 * A surface's `replaces.except` lists path globs (file-name `except` semantics) that keep a generic-name component from mapping to that
 * surface through `replaces.components`. Fixture: a generic Button.vue under components/ui/ (an app's own UI substrate) and one under
 * components/forms/.
 */

/** @param array<string, mixed> $replaces */
function replacesExceptInventory(array $replaces): array
{
    return ['format' => 1, 'package' => ['composer' => 'tey/kit', 'alias' => '@/components/Kit'], 'facts' => [], 'nonSurfaces' => [], 'surfaces' => [[
        'id' => 'action.button', 'layer' => 'ui', 'group' => 'action', 'label' => 'Buttons', 'feature' => 'core', 'applies' => true,
        'uses' => ['imports' => ['@/components/Kit:Button']], 'handRolled' => [], 'replaces' => $replaces, 'docs' => 'docs/x.md', 'status' => 'stable',
    ]]];
}

/** @param array<string, mixed> $replaces */
function replacesExceptReport(array $replaces): array
{
    $base = dirname(__DIR__).'/fixtures/Adoption/replaces-except';
    $report = (new Scanner(new App($base, App::bare($base)), [Inventory::fromArray(replacesExceptInventory($replaces), 'kit.json')], null, new DateTimeImmutable('2026-10-29')))->report();

    return [$report['rows'][0], array_column($report['gaps'], 'subject')];
}

test('without except, both generic Button.vue files map to the surface and make it hand-rolled', function () {
    [$row, $gaps] = replacesExceptReport(['components' => ['Button']]);

    expect($row['status'])->toBe('hand-rolled')
        ->and(array_column($row['replaced'], 'file'))->toEqualCanonicalizing(['resources/js/components/forms/Button.vue', 'resources/js/components/ui/button/Button.vue'])
        ->and($gaps)->toBe([]);
});

test('a component under an except glob no longer counts as hand-rolled, nor as a gap; one outside it still does', function () {
    [$row, $gaps] = replacesExceptReport(['components' => ['Button'], 'except' => ['resources/js/components/ui/**']]);

    expect($row['status'])->toBe('hand-rolled')
        ->and(array_column($row['replaced'], 'file'))->toBe(['resources/js/components/forms/Button.vue'])
        ->and($gaps)->toBe([]);
});

test('when every matching component is excluded the surface no longer reads hand-rolled and nothing becomes a gap', function () {
    [$row, $gaps] = replacesExceptReport(['components' => ['Button'], 'except' => ['resources/js/components/ui/**', 'resources/js/components/forms/**']]);

    expect($row['status'])->toBe('missing')
        ->and($row['replaced'])->toBe([])
        ->and($gaps)->toBe([]);
});

test('a non-list, empty or non-string replaces.except fails closed', function (mixed $except, string $message) {
    expect(fn () => Inventory::fromArray(replacesExceptInventory(['components' => ['Button'], 'except' => $except]), 'kit.json'))
        ->toThrow(AdoptionError::class, $message);
})->with([
    'a string' => ['resources/js/components/ui/**', 'surface [action.button].replaces.except must be a list'],
    'an object' => [['ui' => 'resources/js/components/ui/**'], 'surface [action.button].replaces.except must be a list'],
    'an empty list' => [[], 'surface [action.button].replaces.except must be a non-empty list of strings'],
    'an empty string' => [[''], 'surface [action.button].replaces.except must be a list of non-empty strings'],
    'a non-string' => [[3], 'surface [action.button].replaces.except must be a list of non-empty strings'],
]);
