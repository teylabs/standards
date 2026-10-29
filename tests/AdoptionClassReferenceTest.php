<?php

declare(strict_types=1);

use Tey\Standards\Adoption\AdoptionError;
use Tey\Standards\Adoption\App;
use Tey\Standards\Adoption\Cli;
use Tey\Standards\Adoption\Code;
use Tey\Standards\Adoption\Inventory;
use Tey\Standards\Adoption\Scanner;

/*
 * The `class-reference` signal and file-name `except`, scanned over fixtures/Adoption/class-reference with the kit-cases inventory,
 * where one signal replaces form.files' hand-rolled signals (and applies is forced true).
 */

function classReferenceBase(): string
{
    return dirname(__DIR__).'/fixtures/Adoption/class-reference';
}

/** @return array<string, mixed> the kit-cases inventory with form.files' handRolled replaced */
function classReferenceData(array $signal): array
{
    $data = json_decode((string) file_get_contents(dirname(__DIR__).'/fixtures/Adoption/inventories/kit-cases.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($data['surfaces'] as $index => $surface) {
        if ($surface['id'] === 'form.files') {
            $data['surfaces'][$index] = [...$surface, 'applies' => true, 'handRolled' => [$signal]];
        }
    }

    return $data;
}

/** @return array{status: string, evidence: list<string>} form.files' status and its evidence as `file:line detail` */
function classReferenceRow(array $signal): array
{
    $report = (new Scanner(new App(classReferenceBase()), [Inventory::fromArray(classReferenceData($signal), 'kit-cases.json')], null, new DateTimeImmutable('2026-10-29')))->report();
    $row = collect($report['rows'])->firstWhere('id', 'form.files');

    return ['status' => $row['status'], 'evidence' => $row['status'] === 'hand-rolled' ? array_map(fn (array $e): string => $e['file'].($e['line'] === null ? '' : ':'.$e['line']).' '.$e['detail'], $row['evidence']) : []];
}

test('new Mpdf\Mpdf fires; vendor/, kit-owned files, a comment, a shared prefix and a member name do not', function () {
    expect(classReferenceRow(['kind' => 'class-reference', 'classes' => ['Mpdf\Mpdf']]))->toBe(['status' => 'hand-rolled', 'evidence' => [
        'app/Pdf/ImportOnly.php:5 imports Mpdf\Mpdf',
        'app/Pdf/InvoiceRenderer.php:9 1 × reference to Mpdf\Mpdf',
        'app/Pdf/ReportRenderer.php:9 2 × reference to Mpdf\Mpdf',
    ]]);
});

test('a use-imported facade fires through its short name, and a leading backslash in the inventory is optional', function () {
    $expected = ['status' => 'hand-rolled', 'evidence' => ['app/Pdf/StatementRenderer.php:11 1 × reference to Barryvdh\DomPDF\Facade\Pdf']];

    expect(classReferenceRow(['kind' => 'class-reference', 'classes' => ['Barryvdh\DomPDF\Facade\Pdf']]))->toBe($expected)
        ->and(classReferenceRow(['kind' => 'class-reference', 'classes' => ['\Barryvdh\DomPDF\Facade\Pdf']]))->toBe(['status' => 'hand-rolled', 'evidence' => ['app/Pdf/StatementRenderer.php:11 1 × reference to Barryvdh\DomPDF\Facade\Pdf']]);
});

test('a different class does not fire', function () {
    expect(classReferenceRow(['kind' => 'class-reference', 'classes' => ['Dompdf\Dompdf']]))->toBe(['status' => 'missing', 'evidence' => []]);
});

test('min counts references per file; use plus one call is one reference, and an aliased import with two uses is two', function () {
    expect(classReferenceRow(['kind' => 'class-reference', 'classes' => ['Barryvdh\DomPDF\Facade\Pdf'], 'min' => 2]))->toBe(['status' => 'missing', 'evidence' => []])
        ->and(classReferenceRow(['kind' => 'class-reference', 'classes' => ['Mpdf\Mpdf'], 'min' => 2]))->toBe(['status' => 'hand-rolled', 'evidence' => ['app/Pdf/ReportRenderer.php:9 2 × reference to Mpdf\Mpdf']])
        ->and(classReferenceRow(['kind' => 'class-reference', 'classes' => ['Mpdf\Mpdf', 'Barryvdh\DomPDF\Facade\Pdf'], 'min' => 3]))->toBe(['status' => 'missing', 'evidence' => []]);
});

test('scope reads the PHP path like the UI signals do', function () {
    expect(classReferenceRow(['kind' => 'class-reference', 'classes' => ['Mpdf\Mpdf'], 'scope' => 'public']))->toBe(['status' => 'missing', 'evidence' => []]);
});

test('a class-reference signal computes a fact', function () {
    $data = classReferenceData(['kind' => 'file-name', 'globs' => ['NoSuchFile.vue']]);
    $data['facts'][] = ['id' => 'renders-pdf', 'description' => 'The app renders PDFs with mpdf.', 'computed' => ['signals' => [['kind' => 'class-reference', 'classes' => ['Mpdf\Mpdf']]]]];
    foreach ($data['surfaces'] as $index => $surface) {
        if ($surface['id'] === 'form.files') {
            $data['surfaces'][$index]['applies'] = 'renders-pdf';
        }
    }
    $report = (new Scanner(new App(classReferenceBase()), [Inventory::fromArray($data, 'kit-cases.json')], null, new DateTimeImmutable('2026-10-29')))->report();

    expect($report['facts']['tey/kit']['renders-pdf']['value'])->toBeTrue()
        ->and(collect($report['rows'])->firstWhere('id', 'form.files')['applies'])->toBeTrue();
});

test('the shared reference matcher: FQCN, import, alias, namespace import, type hints and ::class; members, variables and comments are not', function () {
    $source = <<<'PHP'
<?php
use Mpdf\Mpdf as Engine;
use Barryvdh\DomPDF\Facade;
// new Engine in a comment
function a(Engine $e): \Mpdf\Mpdf { return Mpdf\Mpdf::class; }
$x->Engine; $y::Engine; $Engine = 1; Facade\Pdf::loadView('x'); \Barryvdh\DomPDF\Facade\PdfMerger::class;
PHP;
    $mpdf = Code::classReferences($source, 'Mpdf\Mpdf');
    $pdf = Code::classReferences($source, '\Barryvdh\DomPDF\Facade\Pdf');

    expect(array_map(fn (int $o): int => App::line($source, $o), $mpdf['references']))->toBe([5, 5, 5])
        ->and(array_map(fn (int $o): int => App::line($source, $o), $mpdf['imports']))->toBe([2])
        ->and(array_map(fn (int $o): int => App::line($source, $o), $pdf['references']))->toBe([6])
        ->and(Code::classReferences("<?php\nuse Mpdf\\Mpdf;\n", 'Mpdf\Mpdf')['references'])->toBe([]);
});

test('uses.classes, through the shared matcher, still reads a use import and cites the first reference or import', function () {
    $data = classReferenceData(['kind' => 'file-name', 'globs' => ['NoSuchFile.vue']]);
    foreach ($data['surfaces'] as $index => $surface) {
        if ($surface['id'] === 'form.files') {
            $data['surfaces'][$index]['uses'] = ['classes' => ['Mpdf\Mpdf']];
        }
    }
    $report = (new Scanner(new App(classReferenceBase()), [Inventory::fromArray($data, 'kit-cases.json')], null, new DateTimeImmutable('2026-10-29')))->report();
    $row = collect($report['rows'])->firstWhere('id', 'form.files');

    // tey/kit is not installed in the fixture, so the row reads missing; its matches are what uses found.
    expect($row['status'])->toBe('missing')
        ->and(array_map(fn (array $e): string => $e['file'].':'.$e['line'], $row['matches']))->toBe(['app/Pdf/ImportOnly.php:5', 'app/Pdf/InvoiceRenderer.php:9', 'app/Pdf/ReportRenderer.php:5']);
});

test('file-name except removes a file an include glob matched; the other Card still fires', function () {
    expect(classReferenceRow(['kind' => 'file-name', 'globs' => ['*Card.vue'], 'except' => ['**/Components/AuthenticationCard.vue']]))->toBe(['status' => 'hand-rolled', 'evidence' => ['resources/js/Components/StatCard.vue file name matches *Card.vue']])
        ->and(classReferenceRow(['kind' => 'file-name', 'globs' => ['*Card.vue'], 'except' => ['**/components/authenticationcard.vue']]))->toBe(['status' => 'hand-rolled', 'evidence' => ['resources/js/Components/StatCard.vue file name matches *Card.vue']])
        ->and(classReferenceRow(['kind' => 'file-name', 'globs' => ['*Card.vue'], 'except' => ['authenticationcard.vue']])['evidence'])->toHaveCount(2)
        ->and(classReferenceRow(['kind' => 'file-name', 'globs' => ['*Card.vue'], 'except' => ['*Card.vue']]))->toBe(['status' => 'missing', 'evidence' => []])
        ->and(classReferenceRow(['kind' => 'file-name', 'globs' => ['*Card.vue']])['evidence'])->toHaveCount(2);
});

test('class-reference and file-name except fail closed with exit 2', function (array $signal, string $message) {
    expect(fn () => Inventory::fromArray(classReferenceData($signal), 'kit-cases.json'))->toThrow(AdoptionError::class, $message);

    $file = tempnam(sys_get_temp_dir(), 'inventory');
    file_put_contents($file, json_encode(classReferenceData($signal)));
    try {
        expect(Cli::run(['--base', classReferenceBase(), '--inventory', $file, '--json'], dirname(__DIR__))['exit'])->toBe(2);
    } finally {
        unlink($file);
    }
})->with([
    'unknown key' => [['kind' => 'class-reference', 'classes' => ['Mpdf\Mpdf'], 'where' => 'x'], 'unknown field [where]'],
    'missing classes' => [['kind' => 'class-reference'], 'missing required field [classes]'],
    'empty classes' => [['kind' => 'class-reference', 'classes' => []], 'classes must be a non-empty list of strings'],
    'classes a string' => [['kind' => 'class-reference', 'classes' => 'Mpdf\Mpdf'], 'classes must be a list'],
    'classes an object' => [['kind' => 'class-reference', 'classes' => ['a' => 'Mpdf\Mpdf']], 'classes must be a list'],
    'non-string class' => [['kind' => 'class-reference', 'classes' => [1]], 'classes must be a list of non-empty strings'],
    'not a class name' => [['kind' => 'class-reference', 'classes' => ['Mpdf\Mpdf::class']], 'is not a fully qualified class name'],
    'min zero' => [['kind' => 'class-reference', 'classes' => ['Mpdf\Mpdf'], 'min' => 0], 'min must be an integer of at least 1'],
    'min a string' => [['kind' => 'class-reference', 'classes' => ['Mpdf\Mpdf'], 'min' => '2'], 'min must be an integer of at least 1'],
    'bad scope' => [['kind' => 'class-reference', 'classes' => ['Mpdf\Mpdf'], 'scope' => 'guest'], 'is not one of signed-in, public, any'],
    'except a string' => [['kind' => 'file-name', 'globs' => ['*Card.vue'], 'except' => '**/AuthenticationCard.vue'], 'except must be a list'],
    'except empty' => [['kind' => 'file-name', 'globs' => ['*Card.vue'], 'except' => []], 'except must be a non-empty list of strings'],
    'except non-string' => [['kind' => 'file-name', 'globs' => ['*Card.vue'], 'except' => [true]], 'except must be a list of non-empty strings'],
]);
