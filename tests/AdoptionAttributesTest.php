<?php

declare(strict_types=1);

use Tey\Standards\Adoption\AdoptionError;
use Tey\Standards\Adoption\App;
use Tey\Standards\Adoption\Cli;
use Tey\Standards\Adoption\Code;
use Tey\Standards\Adoption\Inventory;
use Tey\Standards\Adoption\Scanner;

/*
 * Template-tag `where` / `except`, and the tey/kit adoption fixture cases (copied from tey/kit tests/fixtures/adoption with that tree's
 * resources/adoption/surfaces.json as inventories/kit-cases.json), read through `bin/standards adoption`'s Cli.
 */

function kitCases(string $path = ''): string
{
    return dirname(__DIR__).'/fixtures/Adoption/kit-cases'.($path === '' ? '' : '/'.$path);
}

function kitCasesInventory(): string
{
    return dirname(__DIR__).'/fixtures/Adoption/inventories/kit-cases.json';
}

/** @return array<string, mixed> the decoded --json report */
function kitCaseReport(string $app, array $extra = []): array
{
    $result = Cli::run(['--base', kitCases($app), '--inventory', kitCasesInventory(), '--json', ...$extra], dirname(__DIR__));

    return [...json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR), 'exit' => $result['exit']];
}

/** @return array{surface: string, facts: array<string, bool>, row: string} */
function kitCaseOutcome(string $app, string $surface): array
{
    $row = collect(kitCaseReport($app)['rows'])->firstWhere('surface', $surface);

    return ['surface' => $row['surface'], 'facts' => $row['facts'], 'row' => $row['row']];
}

/** The attributes page scanned with one template-tag signal as form.files' only hand-rolled signal: [status, evidence detail]. */
function attributeSignal(array $signal): array
{
    $data = json_decode((string) file_get_contents(kitCasesInventory()), true, flags: JSON_THROW_ON_ERROR);
    foreach ($data['surfaces'] as $index => $surface) {
        if ($surface['id'] === 'form.files') {
            $data['surfaces'][$index] = [...$surface, 'applies' => true, 'handRolled' => [['kind' => 'template-tag', ...$signal]]];
        }
    }
    $base = kitCases('attributes');
    $report = (new Scanner(new App($base), [Inventory::fromArray($data, 'kit-cases.json')], null, new DateTimeImmutable('2026-10-29')))->report();
    $row = collect($report['rows'])->firstWhere('id', 'form.files');

    return [$row['status'], $row['status'] === 'hand-rolled' ? $row['evidence'][0]['detail'] : null];
}

function attributeInventory(array $signal): void
{
    $data = json_decode((string) file_get_contents(kitCasesInventory()), true, flags: JSON_THROW_ON_ERROR);
    foreach ($data['surfaces'] as $index => $surface) {
        if ($surface['id'] === 'form.files') {
            $data['surfaces'][$index]['handRolled'] = [['kind' => 'template-tag', 'tags' => ['input'], ...$signal]];
        }
    }
    Inventory::fromArray($data, 'kit-cases.json');
}

test('the kit inventory with where and except loads', function () {
    $inventory = Inventory::load(kitCasesInventory(), 'option');
    $surfaces = collect($inventory->surfaces)->keyBy('id');

    expect($surfaces['form.inputs']['handRolled'][0])->toMatchArray(['kind' => 'template-tag', 'except' => ['type' => ['hidden', 'search', 'file']]])
        ->and($surfaces['form.files']['handRolled'][1])->toMatchArray(['kind' => 'template-tag', 'where' => ['type' => ['file']]])
        ->and($inventory->facts['has-file-uploads']['computed']['signals'][0])->toMatchArray(['where' => ['type' => ['file']]])
        ->and($surfaces['mail.layout']['uses']['components'])->toBe(['x-mail::message', 'mail::message', 'kit-fluent-mail::message'])
        ->and(array_column($surfaces['mail.layout']['handRolled'], 'kind'))->toBe(['mail-view', 'file-name']);
});

test('each kit fixture case reads the row its expected.json names, except mail-hand-rolled', function (string $app) {
    $case = collect(json_decode((string) file_get_contents(kitCases('expected.json')), true, flags: JSON_THROW_ON_ERROR)['cases'])->firstWhere('app', $app);

    expect(kitCaseOutcome($app, $case['surface']))->toBe(['surface' => $case['surface'], 'facts' => $case['facts'], 'row' => $case['row']]);
})->with(['mail-adopted', 'inputs-hidden-search', 'inputs-raw', 'inputs-in-kit', 'files-raw']);

test('mail-hand-rolled as kit ships it sends no mail, so mail.layout is n/a; with a mail notification it reads hand-rolled on both signals', function () {
    // The kit tree has only a published layout and no Mailable or Notification, so sends-mail (mail-notifications) is false.
    expect(kitCaseOutcome('mail-hand-rolled', 'mail.layout'))->toBe(['surface' => 'mail.layout', 'facts' => ['sends-mail' => false], 'row' => 'n/a'])
        ->and(kitCaseOutcome('mail-hand-rolled-notifying', 'mail.layout'))->toBe(['surface' => 'mail.layout', 'facts' => ['sends-mail' => true], 'row' => 'hand-rolled']);

    $row = collect(kitCaseReport('mail-hand-rolled-notifying')['rows'])->firstWhere('surface', 'mail.layout');
    expect(array_column($row['evidence'], 'detail'))->toBe(['a mail view without mail::message, x-mail::message, kit-fluent-mail::message', 'file name matches resources/views/vendor/mail/**']);
});

test('hidden inputs and a lone search box do not hand-roll form.inputs; except hidden repeats the built-in drop without double counting', function () {
    $row = collect(kitCaseReport('inputs-hidden-search')['rows'])->firstWhere('surface', 'form.inputs');
    expect($row['row'])->toBe('missing')
        ->and(attributeSignal(['tags' => ['input'], 'except' => ['type' => ['search']]]))->toBe(['hand-rolled', '5 × <input> except type=search in a page'])
        ->and(attributeSignal(['tags' => ['input'], 'except' => ['type' => ['hidden', 'search']]]))->toBe(['hand-rolled', '5 × <input> except type=hidden|search in a page'])
        ->and(attributeSignal(['tags' => ['input']]))->toBe(['hand-rolled', '6 × <input> in a page']);
});

test('where matches only a static attribute with a listed value; bound and missing attributes never match', function () {
    expect(attributeSignal(['tags' => ['input'], 'where' => ['type' => ['file']]]))->toBe(['hand-rolled', '1 × <input> where type=file in a page'])
        ->and(attributeSignal(['tags' => ['input'], 'where' => ['type' => ['kind']]]))->toBe(['missing', null])
        ->and(attributeSignal(['tags' => ['input'], 'where' => ['type' => ['FILE', 'text']]]))->toBe(['hand-rolled', '2 × <input> where type=FILE|text in a page'])
        ->and(attributeSignal(['tags' => ['input'], 'where' => ['type' => ['file'], 'name' => ['bound']]]))->toBe(['missing', null])
        ->and(attributeSignal(['tags' => ['input'], 'where' => ['type' => ['file']], 'min' => 2]))->toBe(['missing', null]);
});

test('except drops a static listed value only; bound and missing attributes are kept, and it composes with where and min', function () {
    expect(attributeSignal(['tags' => ['input'], 'except' => ['type' => ['hidden', 'search', 'file']]]))->toBe(['hand-rolled', '4 × <input> except type=hidden|search|file in a page'])
        ->and(attributeSignal(['tags' => ['input'], 'except' => ['type' => ['kind']]]))->toBe(['hand-rolled', '6 × <input> except type=kind in a page'])
        ->and(attributeSignal(['tags' => ['input'], 'where' => ['type' => ['file', 'text']], 'except' => ['name' => ['cover']]]))->toBe(['hand-rolled', '1 × <input> where type=file|text except name=cover in a page'])
        ->and(attributeSignal(['tags' => ['input'], 'except' => ['type' => ['hidden', 'search', 'file']], 'min' => 5]))->toBe(['missing', null]);
});

test('static attributes skip bound, directive and event attributes and read past a > inside a quoted value', function () {
    $source = '<input :type="t" v-bind:name="n" @input="go" v-model="m" #slot DISABLED type=\'Text\' data-x=1 :title="a > b" name="last"><b>';

    expect(Code::staticAttributes($source, 0))->toBe(['disabled' => '', 'type' => 'Text', 'data-x' => '1', 'name' => 'last'])
        ->and(Code::attributesPass(['type' => 'Text'], ['type' => ['text']], []))->toBeTrue()
        ->and(Code::attributesPass([], ['type' => ['text']], []))->toBeFalse()
        ->and(Code::attributesPass([], [], ['type' => ['text']]))->toBeTrue();
});

test('where and except fail closed on a malformed value', function (array $signal, string $message) {
    expect(fn () => attributeInventory($signal))->toThrow(AdoptionError::class, $message);
})->with([
    'unknown signal key' => [['only' => ['type' => ['file']]], 'unknown field [only]'],
    'a list, not an object' => [['where' => ['file']], 'must be a non-empty object'],
    'an empty object' => [['except' => []], 'must be a non-empty object'],
    'a string, not a list' => [['where' => ['type' => 'file']], 'where.type must be a non-empty list of strings'],
    'an empty list' => [['except' => ['type' => []]], 'except.type must be a non-empty list of strings'],
    'a non-string literal' => [['where' => ['type' => [1]]], 'where.type must be a list of non-empty strings'],
    'a nested object' => [['where' => ['type' => ['a' => 'file']]], 'where.type must be a non-empty list of strings'],
    'a bound attribute name' => [['where' => [':type' => ['file']]], 'is not a static attribute name'],
]);

test('bin/standards adoption reads a bare tree with the default configuration and carries the expected.json row fields', function () {
    $report = kitCaseReport('files-raw');
    $row = collect($report['rows'])->firstWhere('surface', 'form.files');

    expect($report['exit'])->toBe(0)
        ->and($row)->toHaveKeys(['package', 'id', 'status', 'evidence', 'surface', 'facts', 'row'])
        ->and($row['evidence'][0]['file'].':'.$row['evidence'][0]['line'])->toBe('resources/js/pages/Posts/Page.vue:1');
});

test('bin/standards adoption exits 2 on an unreadable inventory, a schema error or bad usage, and 1 on a --compliance finding', function () {
    $root = dirname(__DIR__);
    $missing = Cli::run(['--base', kitCases('files-raw'), '--inventory', 'no/such.json'], $root);
    $schema = Cli::run(['--base', kitCases('files-raw'), '--inventory', 'fixtures/Adoption/kit-cases/expected.json', '--json'], $root);
    $usage = Cli::run(['--bogus'], $root);
    // As with standards:adoption: --inventory may describe tey/kit under --compliance only where the app doesn't install it (there its
    // applicable rows read missing and block); where the app requires tey/kit, --compliance refuses the override.
    $compliance = Cli::run(['--base', kitCases('not-installed'), '--inventory', kitCasesInventory(), '--compliance'], $root);
    $report = Cli::run(['--base', kitCases('not-installed'), '--inventory', kitCasesInventory()], $root);
    $refused = Cli::run(['--base', kitCases('inputs-raw'), '--inventory', kitCasesInventory(), '--compliance'], $root);

    expect($missing['exit'])->toBe(2)->and($missing['stderr'])->toContain('Cannot read the adoption inventory')
        ->and($schema['exit'])->toBe(2)->and(json_decode($schema['stdout'], true)['exit_code'])->toBe(2)
        ->and($usage['exit'])->toBe(2)
        ->and($compliance['exit'])->toBe(1)
        ->and($report['exit'])->toBe(0)
        ->and($refused['exit'])->toBe(2)->and($refused['stderr'])->toContain('which the app installs');
});

test('the bin/standards adoption script runs the Cli', function () {
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/standards').' adoption --base='.escapeshellarg(kitCases('inputs-raw')).' --inventory='.escapeshellarg(kitCasesInventory()).' --json';
    exec($command, $output, $exit);
    $row = collect(json_decode(implode("\n", $output), true)['rows'])->firstWhere('surface', 'form.inputs');

    expect($exit)->toBe(0)->and([$row['facts'], $row['row']])->toBe([['has-forms' => true], 'hand-rolled']);
});

test('bin/standards adoption runs a {kind: rule} ui.kit-shell signal on a bare tree with the default configuration, firing and quiet', function () {
    $root = dirname(__DIR__);
    $inventory = $root.'/fixtures/Adoption/inventories/kit.json';
    $layout = function (string $tree) use ($root, $inventory): array {
        expect(is_file($root.'/fixtures/Adoption/'.$tree.'/'.Cli::CONFIGURATION))->toBeFalse('the tree must be bare');
        $result = Cli::run(['--base', $root.'/fixtures/Adoption/'.$tree, '--inventory', $inventory, '--json'], $root);
        expect($result['exit'])->toBe(0, $result['stdout'].$result['stderr']);
        $row = collect(json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR)['rows'])->firstWhere('id', 'layout.page');

        return [$row['status'], array_map(fn (array $e): string => $e['file'].(isset($e['line']) ? ':'.$e['line'] : '').' '.$e['detail'], $row['evidence'])];
    };

    expect($layout('hand-rolled'))->toBe(['hand-rolled', ['resources/js/layouts/AppHeader.vue:6 [ui.kit-shell] signed-in <header> outside kit\'s shell']])
        ->and($layout('adopted'))->toBe(['adopted', ['resources/js/pages/Dashboard/Page.vue:3 imports Page from @/components/Kit']]);
});
