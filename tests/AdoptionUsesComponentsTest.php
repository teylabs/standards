<?php

declare(strict_types=1);

use Tey\Standards\Adoption\App;
use Tey\Standards\Adoption\Inventory;
use Tey\Standards\Adoption\Scanner;

/*
 * `uses.components` matches PascalCase component tags case-sensitively: `<Button>`, a namespaced `<Kit.Button>`, a local alias that the
 * file's kit import binds to the listed component, or a kebab-case tag that resolves to such an import. A native `<button>` is never
 * adoption evidence; it stays hand-rolled evidence through template-tag signals. Throwaway apps in a temp folder.
 */

/** @param array<string, string> $files */
function usesComponentsApp(array $files): string
{
    $base = sys_get_temp_dir().'/standards-uses-components-'.bin2hex(random_bytes(6));
    $files += ['composer.json' => json_encode(['name' => 'fixture/uses-components', 'require' => ['tey/kit' => '^1.0']])];
    foreach ($files as $path => $contents) {
        if (! is_dir(dirname($base.'/'.$path))) {
            mkdir(dirname($base.'/'.$path), 0777, true);
        }
        file_put_contents($base.'/'.$path, $contents);
    }

    return $base;
}

/**
 * @param  array<string, string>  $files
 * @param  list<array<string, mixed>>  $handRolled
 * @param  list<string>  $components
 */
function usesComponentsRow(array $files, array $handRolled = [], array $components = ['Button']): array
{
    $base = usesComponentsApp($files);
    try {
        $inventory = Inventory::fromArray(['format' => 1, 'package' => ['composer' => 'tey/kit', 'alias' => '@/components/Kit'], 'facts' => [], 'nonSurfaces' => [], 'surfaces' => [[
            'id' => 'action.button', 'layer' => 'ui', 'group' => 'action', 'label' => 'Buttons', 'feature' => 'core', 'applies' => true,
            'uses' => ['components' => $components], 'handRolled' => $handRolled, 'docs' => 'docs/x.md', 'status' => 'stable',
        ]]], 'uses-components.json');

        return (new Scanner(new App($base, App::bare($base)), [$inventory], null, new DateTimeImmutable('2026-10-29')))->report()['rows'][0];
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($base);
    }
}

function usesComponentsPage(string $script, string $template): array
{
    return ['resources/js/pages/Orders/Page.vue' => ($script === '' ? '' : "<script setup lang=\"ts\">\n{$script}\n</script>\n\n")."<template>\n  {$template}\n</template>\n"];
}

test('a native <button> is not adoption evidence for uses.components Button', function () {
    $row = usesComponentsRow(usesComponentsPage('', '<button type="submit">Save</button>'));

    expect($row['status'])->toBe('missing')
        ->and($row['matches'])->toBe([]);
});

test('a native <button> in any case is not adoption evidence', function (string $tag) {
    expect(usesComponentsRow(usesComponentsPage('', "<{$tag}>Save</{$tag}>"))['status'])->toBe('missing');
})->with(['button', 'BUTTON', 'bUTTON']);

test('<Button> imported from @/components/Kit is adopted', function () {
    $row = usesComponentsRow(usesComponentsPage("import { Button } from '@/components/Kit';", '<Button>Save</Button>'));

    expect($row['status'])->toBe('adopted')
        ->and(array_column($row['matches'], 'detail'))->toBe(['renders <Button>']);
});

test('<Button> that the file imports from a non-kit module is not adopted', function () {
    $row = usesComponentsRow(usesComponentsPage("import Button from '@/components/forms/Button.vue';", '<Button>Save</Button>'));

    expect($row['status'])->toBe('missing');
});

test('a local alias bound by the kit import is adopted', function () {
    $row = usesComponentsRow(usesComponentsPage("import { Button as KButton } from '@/components/Kit';", '<KButton>Save</KButton>'));

    expect($row['status'])->toBe('adopted')
        ->and(array_column($row['matches'], 'detail'))->toBe(['renders <KButton>']);
});

test('a default import of a deep kit module under a local name is adopted', function () {
    $row = usesComponentsRow(usesComponentsPage("import KButton from '@/components/Kit/Button.vue';", '<KButton>Save</KButton>'));

    expect($row['status'])->toBe('adopted');
});

test('the same alias imported from a non-kit module is not adopted', function () {
    $row = usesComponentsRow(usesComponentsPage("import { Button as KButton } from '@/components/forms';", '<KButton>Save</KButton>'));

    expect($row['status'])->toBe('missing');
});

test('a namespaced <Kit.Button> is adopted, and one bound by a namespace import of kit too', function (string $script, string $tag) {
    expect(usesComponentsRow(usesComponentsPage($script, "<{$tag}>Save</{$tag}>"))['status'])->toBe('adopted');
})->with([
    'Kit.Button' => ['', 'Kit.Button'],
    'UI.Button through import * as UI' => ["import * as UI from '@kit/ui';", 'UI.Button'],
]);

test('a namespace that is neither Kit nor a kit namespace import is not adopted', function () {
    expect(usesComponentsRow(usesComponentsPage("import * as Forms from '@/components/forms';", '<Forms.Button>Save</Forms.Button>'))['status'])->toBe('missing');
});

test('a kebab-case tag counts only when it resolves to a kit import of the listed component', function (string $script, string $status) {
    $row = usesComponentsRow(usesComponentsPage($script, '<data-table :rows="rows" />'), [], ['DataTable']);

    expect($row['status'])->toBe($status);
})->with([
    'imported from kit' => ["import { DataTable } from '@/components/Kit';", 'adopted'],
    'aliased from kit' => ["import { DataTable as DataGrid } from '@/components/Kit';", 'missing'],
    'not imported' => ['', 'missing'],
    'imported from elsewhere' => ["import { DataTable } from '@/components/tables';", 'missing'],
]);

test('a kebab-case form of a kit alias counts', function () {
    $row = usesComponentsRow(usesComponentsPage("import { DataTable as KitGrid } from '@/components/Kit';", '<kit-grid />'), [], ['DataTable']);

    expect($row['status'])->toBe('adopted');
});

test('a template-tag hand-rolled signal on <button> still fires, and the native tag is not also adoption evidence', function () {
    $row = usesComponentsRow(usesComponentsPage('', '<button type="submit">Save</button>'), [['kind' => 'template-tag', 'tags' => ['button']]]);

    expect($row['status'])->toBe('hand-rolled')
        ->and(array_column($row['signals'], 'detail'))->toBe(['1 × <button> in a page'])
        ->and($row['matches'])->toBe([]);
});

test('a file-name hit that renders only a native <button> does not yield to uses', function () {
    $row = usesComponentsRow(
        ['resources/js/components/SaveButton.vue' => "<template>\n  <button type=\"submit\"><slot /></button>\n</template>\n"],
        [['kind' => 'file-name', 'globs' => ['*Button.vue']]],
    );

    expect($row['status'])->toBe('hand-rolled')
        ->and(array_column($row['signals'], 'file'))->toBe(['resources/js/components/SaveButton.vue'])
        ->and($row['matches'])->toBe([]);
});

test('a file-name hit that renders a kit alias yields to uses', function () {
    $row = usesComponentsRow(
        ['resources/js/components/SaveButton.vue' => "<script setup lang=\"ts\">\nimport { Button as KButton } from '@/components/Kit';\n</script>\n\n<template>\n  <KButton><slot /></KButton>\n</template>\n"],
        [['kind' => 'file-name', 'globs' => ['*Button.vue']]],
    );

    expect($row['status'])->toBe('adopted')
        ->and($row['signals'])->toBe([]);
});
