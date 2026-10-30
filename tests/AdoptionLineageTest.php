<?php

declare(strict_types=1);

use Tey\Standards\Adoption\App;
use Tey\Standards\Adoption\Inventory;
use Tey\Standards\Adoption\Scanner;

/*
 * The installed tey/mod lineage record (.mod-maintenance/installed/*.json, the record kit:status reads) is the primary adoption evidence
 * for kit-adopted and kit-created tables; kit_adoptions only adds to it. Throwaway apps in a temp folder; the record shape is what tey/kit's
 * Lineages::adoption writes for a host that owns its workspaces table.
 */

const LINEAGE_MIGRATION = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        Schema::create('workspaces', function (Blueprint \$table) {\n            \$table->id();\n        });\n    }\n};\n";

function lineageRecordPath(): string
{
    return '.mod-maintenance/installed/'.str_repeat('1', 64).'.json';
}

/** @param array<string, mixed>|null $metadata */
function lineageRecord(?array $metadata = ['adopt' => ['stamp' => '2026_10_29_000001', 'tables' => ['permission', 'workspaces']], 'feature' => 'workspaces']): string
{
    return (string) json_encode(['format' => 1, 'owner' => 'tey/mod:installed', 'app_id' => 'northwind', 'root' => '.', 'artifact' => [
        'identity' => 'sha256:'.str_repeat('a', 64), 'name' => 'tey/kit:workspaces', 'version' => '0.1.0', 'files' => [], 'metadata' => $metadata,
    ]]);
}

/** @param array<string, string|null> $extra path => contents, null to leave the default file out */
function lineageApp(array $extra = [], bool $kitRequiresMultitenancy = true): string
{
    $base = sys_get_temp_dir().'/standards-lineage-'.bin2hex(random_bytes(6));
    $files = array_filter($extra + [
        'composer.json' => json_encode(['name' => 'fixture/northwind', 'require' => ['tey/kit' => '^1.0', 'spatie/laravel-multitenancy' => '^4.2']]),
        'composer.lock' => json_encode(['packages' => [['name' => 'tey/kit', 'version' => '1.0.0', 'require' => $kitRequiresMultitenancy ? ['php' => '^8.3', 'spatie/laravel-multitenancy' => '^4.2'] : ['php' => '^8.3']]]]),
        'database/migrations/2026_09_22_000000_create_workspaces_table.php' => LINEAGE_MIGRATION,
        lineageRecordPath() => lineageRecord(),
    ], fn (?string $contents): bool => $contents !== null);
    foreach ($files as $path => $contents) {
        if (! is_dir(dirname($base.'/'.$path))) {
            mkdir(dirname($base.'/'.$path), 0777, true);
        }
        file_put_contents($base.'/'.$path, $contents);
    }

    return $base;
}

function lineageCleanup(string $base): void
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($base);
}

/** @param list<string> $adoptedTables kit_adoptions rows (empty: an empty database) */
function lineageRow(string $base, array $adoptedTables = []): array
{
    try {
        $inventory = Inventory::fromArray(['format' => 1, 'package' => ['composer' => 'tey/kit', 'alias' => '@/components/Kit'], 'facts' => [], 'nonSurfaces' => [], 'surfaces' => [[
            'id' => 'workspaces.tenancy', 'layer' => 'backend', 'group' => 'workspace', 'label' => 'Workspaces', 'feature' => 'workspaces', 'applies' => true,
            'uses' => ['classes' => ['Tey\\Kit\\Features\\Workspaces\\Concerns\\IsKitWorkspace']],
            'handRolled' => [['kind' => 'migration', 'tables' => ['teams', 'workspaces']], ['kind' => 'composer', 'packages' => ['stancl/tenancy', 'spatie/laravel-multitenancy']]],
            'docs' => 'docs/models.md', 'status' => 'stable',
        ]]], 'lineage.json');

        return (new Scanner(new App($base, App::bare($base)), [$inventory], null, new DateTimeImmutable('2026-10-29'), $adoptedTables))->report()['rows'][0];
    } finally {
        lineageCleanup($base);
    }
}

test('lineage installed, empty database: the adopted table is adopted and kit\'s own multitenancy dependency is no signal', function () {
    $row = lineageRow(lineageApp());

    expect($row['status'])->toBe('adopted')
        ->and($row['installed'])->toBeTrue()
        ->and($row['signals'])->toBe([])
        ->and($row['matches'][0]['file'])->toBe('database/migrations/2026_09_22_000000_create_workspaces_table.php')
        ->and($row['matches'][0]['detail'])->toBe('creates table workspaces, adopted in place by lineage tey/kit:workspaces ('.lineageRecordPath().')')
        ->and($row['note'])->toContain('spatie/laravel-multitenancy required by tey/kit itself');
});

test('the app requiring multitenancy when kit itself does not stays a hand-rolled signal', function () {
    $row = lineageRow(lineageApp(kitRequiresMultitenancy: false));

    expect($row['status'])->toBe('hand-rolled')
        ->and(array_column($row['signals'], 'detail'))->toBe(['requires spatie/laravel-multitenancy']);
});

test('no lineage plus the migration: hand-rolled', function () {
    $row = lineageRow(lineageApp([lineageRecordPath() => null]));

    expect($row['status'])->toBe('hand-rolled')
        ->and(array_column($row['signals'], 'detail'))->toBe(['creates table workspaces', 'requires spatie/laravel-multitenancy'])
        ->and($row['installed'])->toBeFalse();
});

test('a malformed lineage file is absent, with a note on the row', function (string $contents) {
    $row = lineageRow(lineageApp([lineageRecordPath() => $contents]));

    expect($row['status'])->toBe('hand-rolled')
        ->and($row['installed'])->toBeFalse()
        ->and(array_column($row['signals'], 'detail'))->toContain('creates table workspaces')
        ->and($row['note'])->toContain('lineage record treated as absent: '.lineageRecordPath());
})->with([
    'not JSON' => ['{"format": 1, "owner": "tey/mod:installed", '],
    'bad adopt' => [lineageRecord(['adopt' => ['stamp' => 'yesterday', 'tables' => ['workspaces']]])],
]);

test('kit_adoptions rows without a lineage still adopt the table (the supplement)', function () {
    $row = lineageRow(lineageApp([lineageRecordPath() => null]), ['workspaces']);

    expect(array_column($row['signals'], 'detail'))->toBe(['requires spatie/laravel-multitenancy'])
        ->and(array_column($row['matches'], 'detail'))->toBe(['creates table workspaces, recorded in kit_adoptions (adopted in place)']);
});

test('a lineage without adopt metadata still adopts a table its recorded migration creates', function () {
    $record = json_decode(lineageRecord(null), true);
    $record['artifact']['files'] = ['database/migrations/kit/workspaces/0001_create_kit_workspaces_table.php' => base64_encode(LINEAGE_MIGRATION)];
    $row = lineageRow(lineageApp([lineageRecordPath() => json_encode($record)]));

    expect($row['status'])->toBe('adopted')
        ->and($row['matches'][0]['detail'])->toStartWith('creates table workspaces, created by lineage tey/kit:workspaces');
});
