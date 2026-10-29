<?php

declare(strict_types=1);

use Tey\Standards\Adoption\AdoptionError;
use Tey\Standards\Adoption\App;
use Tey\Standards\Adoption\Cli;
use Tey\Standards\Adoption\Code;
use Tey\Standards\Adoption\Inventory;
use Tey\Standards\Adoption\Scanner;

/*
 * Blade as template-tag evidence (`in: ['blade']`), scanned over fixtures/Adoption/blade with the kit-cases inventory, where one signal
 * replaces data.table's hand-rolled signals (and applies is forced true).
 */

function bladeBase(string $path = ''): string
{
    return dirname(__DIR__).'/fixtures/Adoption/blade'.($path === '' ? '' : '/'.$path);
}

/** @return array<string, mixed> the kit-cases inventory with data.table's handRolled replaced */
function bladeData(array $signal): array
{
    $data = json_decode((string) file_get_contents(dirname(__DIR__).'/fixtures/Adoption/inventories/kit-cases.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($data['surfaces'] as $index => $surface) {
        if ($surface['id'] === 'data.table') {
            $data['surfaces'][$index] = [...$surface, 'applies' => true, 'handRolled' => [$signal]];
        } else {
            // Only data.table is read: the others (layout.page's rule signal among them) never apply.
            $data['surfaces'][$index]['applies'] = ['not' => true];
        }
    }

    return $data;
}

/** @return array{status: string, evidence: list<string>} data.table's status and its evidence as `file:line detail` */
function bladeRow(array $signal, string $base = ''): array
{
    $report = (new Scanner(new App($base === '' ? bladeBase() : $base), [Inventory::fromArray(bladeData($signal), 'kit-cases.json')], null, new DateTimeImmutable('2026-10-29')))->report();
    $row = collect($report['rows'])->firstWhere('id', 'data.table');

    return ['status' => $row['status'], 'evidence' => array_map(fn (array $e): string => $e['file'].($e['line'] === null ? '' : ':'.$e['line']).' '.$e['detail'], $row['signals'])];
}

/** @return list<string> the evidence files only */
function bladeFiles(array $signal): array
{
    return array_map(fn (string $e): string => (string) strtok($e, ' '), bladeRow($signal)['evidence']);
}

test('bladeFiles reads resources/views except mail, pdf, vendor and errors; Livewire and Volt views are ordinary views', function () {
    expect((new App(bladeBase()))->bladeFiles())->toBe([
        'resources/views/components/orders/summary.blade.php',
        'resources/views/help/index.blade.php',
        'resources/views/livewire/order-board.blade.php',
        'resources/views/livewire/pages/settings.blade.php',
        'resources/views/orders/export.blade.php',
        'resources/views/orders/index.blade.php',
        'resources/views/welcome.blade.php',
    ]);
});

test('template-tag in blade reads every Blade view outside the excluded folders, with scope any', function () {
    expect(bladeRow(['kind' => 'template-tag', 'tags' => ['table'], 'in' => ['blade']]))->toBe(['status' => 'hand-rolled', 'evidence' => [
        'resources/views/components/orders/summary.blade.php:1 1 × <table> in a Blade view',
        'resources/views/livewire/order-board.blade.php:2 1 × <table> in a Blade view',
        'resources/views/livewire/pages/settings.blade.php:10 1 × <table> in a Blade view',
        'resources/views/orders/export.blade.php:1 1 × <table> in a Blade view',
        'resources/views/orders/index.blade.php:13 1 × <table> in a Blade view',
        'resources/views/welcome.blade.php:8 1 × <table> in a Blade view',
    ]]);
});

test('in defaults to vue, so an inventory without it never reads Blade; vue and blade together read both', function () {
    expect(bladeRow(['kind' => 'template-tag', 'tags' => ['table']]))->toBe(['status' => 'missing', 'evidence' => []])
        ->and(bladeRow(['kind' => 'template-tag', 'tags' => ['table'], 'in' => ['vue']]))->toBe(['status' => 'missing', 'evidence' => []])
        ->and(bladeRow(['kind' => 'template-tag', 'tags' => ['table'], 'in' => ['vue', 'blade']])['evidence'])->toHaveCount(6);
});

test('until a route reaches it, a Blade hit reads scope unknown: it matches scope any only, never signed-in or public', function () {
    expect(bladeRow(['kind' => 'template-tag', 'tags' => ['table'], 'in' => ['blade'], 'scope' => 'any'])['evidence'])->toHaveCount(6)
        ->and(bladeRow(['kind' => 'template-tag', 'tags' => ['table'], 'in' => ['blade'], 'scope' => 'signed-in'])['evidence'])->not->toContain('resources/views/components/orders/summary.blade.php:1 1 × <table> in a Blade view')
        ->and(bladeRow(['kind' => 'template-tag', 'tags' => ['table'], 'in' => ['blade'], 'scope' => 'public'])['evidence'])->not->toContain('resources/views/components/orders/summary.blade.php:1 1 × <table> in a Blade view');
});

test('a tag in a Blade comment, an HTML comment, @verbatim, an echo or a Volt PHP block is not markup', function () {
    expect(bladeRow(['kind' => 'template-tag', 'tags' => ['dialog'], 'in' => ['blade']]))->toBe(['status' => 'missing', 'evidence' => []]);
});

test('component tags match as written: x-button, x-dir.name and x-dir::name; an HTML tag name never matches a component', function () {
    expect(bladeRow(['kind' => 'template-tag', 'tags' => ['x-button'], 'in' => ['blade']])['evidence'])->toBe([
        'resources/views/livewire/pages/settings.blade.php:11 1 × <x-button> in a Blade view',
        'resources/views/orders/index.blade.php:11 1 × <x-button> in a Blade view',
        'resources/views/welcome.blade.php:9 1 × <x-button> in a Blade view',
    ])
        ->and(bladeRow(['kind' => 'template-tag', 'tags' => ['x-orders.summary'], 'in' => ['blade']])['evidence'])->toBe(['resources/views/orders/index.blade.php:16 1 × <x-orders.summary> in a Blade view'])
        ->and(bladeRow(['kind' => 'template-tag', 'tags' => ['x-orders::badge'], 'in' => ['blade']])['evidence'])->toBe(['resources/views/orders/index.blade.php:17 1 × <x-orders::badge> in a Blade view'])
        ->and(bladeRow(['kind' => 'template-tag', 'tags' => ['button'], 'in' => ['blade']]))->toBe(['status' => 'missing', 'evidence' => []])
        ->and(bladeRow(['kind' => 'template-tag', 'tags' => ['XButton'], 'in' => ['blade']]))->toBe(['status' => 'missing', 'evidence' => []]);
});

test('where and except read static Blade attributes only; :attr, an echoed value, @class and {{ $attributes }} match neither', function () {
    // index.blade.php: hidden (never counts), search (except), :type, type="{{ }}", @class + type="text", {{ $attributes->merge }}.
    expect(bladeRow(['kind' => 'template-tag', 'tags' => ['input'], 'in' => ['blade'], 'except' => ['type' => ['hidden', 'search', 'file']]])['evidence'])->toBe(['resources/views/orders/index.blade.php:7 4 × <input> except type=hidden|search|file in a Blade view'])
        ->and(bladeRow(['kind' => 'template-tag', 'tags' => ['input'], 'in' => ['blade'], 'where' => ['type' => ['text']]])['evidence'])->toBe(['resources/views/orders/index.blade.php:9 1 × <input> where type=text in a Blade view'])
        ->and(bladeRow(['kind' => 'template-tag', 'tags' => ['input'], 'in' => ['blade'], 'where' => ['type' => ['email']]]))->toBe(['status' => 'missing', 'evidence' => []])
        ->and(bladeRow(['kind' => 'template-tag', 'tags' => ['input'], 'in' => ['blade'], 'where' => ['type' => ['hidden']]]))->toBe(['status' => 'missing', 'evidence' => []])
        ->and(bladeRow(['kind' => 'template-tag', 'tags' => ['input'], 'in' => ['blade'], 'min' => 6]))->toBe(['status' => 'missing', 'evidence' => []])
        ->and(bladeRow(['kind' => 'template-tag', 'tags' => ['input'], 'in' => ['blade'], 'min' => 5]))->toBe(['status' => 'hand-rolled', 'evidence' => ['resources/views/orders/index.blade.php:6 5 × <input> in a Blade view']]);
});

test('Blade static attributes skip bound, echoed and directive attributes and read past -> inside an echo', function () {
    $source = '<input :type="$t" type="{{ $a->b }}" @class([\'x\' => $y > 1]) {{ $attributes->merge([\'type\' => \'email\']) }} x-bind:value="v" name="q" NAME="r" required>';

    expect(Code::bladeAttributes($source, 0))->toBe(['name' => 'q', 'required' => ''])
        ->and(Code::bladeAttributes('<x-button type="submit" :disabled="$busy">', 0))->toBe(['type' => 'submit']);
});

test('file-name signals reach Blade paths, including views bladeFiles leaves out', function () {
    expect(bladeFiles(['kind' => 'file-name', 'globs' => ['resources/views/orders/*.blade.php']]))->toBe(['resources/views/orders/export.blade.php', 'resources/views/orders/index.blade.php'])
        ->and(bladeFiles(['kind' => 'file-name', 'globs' => ['resources/views/pdf/**']]))->toBe(['resources/views/pdf/invoice.blade.php']);
});

test('template-tag in fails closed with exit 2', function (array $signal, string $message) {
    expect(fn () => Inventory::fromArray(bladeData($signal), 'kit-cases.json'))->toThrow(AdoptionError::class, $message);

    $file = tempnam(sys_get_temp_dir(), 'inventory');
    file_put_contents($file, json_encode(bladeData($signal)));
    try {
        expect(Cli::run(['--base', bladeBase(), '--inventory', $file, '--json'], dirname(__DIR__))['exit'])->toBe(2);
    } finally {
        unlink($file);
    }
})->with([
    'a string' => [['kind' => 'template-tag', 'tags' => ['table'], 'in' => 'blade'], 'in must be a non-empty list of vue, blade'],
    'empty' => [['kind' => 'template-tag', 'tags' => ['table'], 'in' => []], 'in must be a non-empty list of vue, blade'],
    'an object' => [['kind' => 'template-tag', 'tags' => ['table'], 'in' => ['a' => 'blade']], 'in must be a non-empty list of vue, blade'],
    'unknown value' => [['kind' => 'template-tag', 'tags' => ['table'], 'in' => ['html']], '"html" is not one of vue, blade'],
    'non-string value' => [['kind' => 'template-tag', 'tags' => ['table'], 'in' => [1]], '1 is not one of vue, blade'],
    'duplicate' => [['kind' => 'template-tag', 'tags' => ['table'], 'in' => ['blade', 'blade']], 'in lists a format twice'],
    'on another kind' => [['kind' => 'file-name', 'globs' => ['*.blade.php'], 'in' => ['blade']], 'unknown field [in]'],
]);
