<?php

declare(strict_types=1);

use Tey\Standards\Adoption\App;
use Tey\Standards\Adoption\Inventory;
use Tey\Standards\Adoption\Scanner;

/*
 * A `file-name` hit yields when the file imports one of the surface's `uses.files`: a layout wrapper (`AppLayout.vue`) that renders the
 * kit's own `pages/Workspace/Layout.vue` and only fills its slots is adoption, not a hand-rolled shell. Throwaway apps in a temp folder.
 */

/** @param array<string, string> $files */
function usesFilesShellRow(array $files): array
{
    $base = sys_get_temp_dir().'/standards-uses-files-'.bin2hex(random_bytes(6));
    $files += ['composer.json' => json_encode(['name' => 'fixture/uses-files', 'require' => ['tey/kit' => '^1.0']])];
    foreach ($files as $path => $contents) {
        if (! is_dir(dirname($base.'/'.$path))) {
            mkdir(dirname($base.'/'.$path), 0777, true);
        }
        file_put_contents($base.'/'.$path, $contents);
    }
    try {
        $inventory = Inventory::fromArray(['format' => 1, 'package' => ['composer' => 'tey/kit', 'alias' => '@/components/Kit'], 'facts' => [], 'nonSurfaces' => [], 'surfaces' => [[
            'id' => 'shell.workspace', 'layer' => 'ui', 'group' => 'layout', 'label' => 'Workspace shell', 'feature' => 'shell', 'applies' => true,
            'uses' => ['components' => ['PrimaryNav'], 'files' => ['resources/js/pages/Workspace/Layout.vue']],
            'handRolled' => [['kind' => 'file-name', 'globs' => ['resources/js/**/layouts/AppLayout.vue']]], 'docs' => 'docs/x.md', 'status' => 'stable',
        ]]], 'uses-files.json');

        return (new Scanner(new App($base, App::bare($base)), [$inventory], null, new DateTimeImmutable('2026-11-16')))->report()['rows'][0];
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($base);
    }
}

$kitLayout = ['resources/js/pages/Workspace/Layout.vue' => "<template><div><slot /></div></template>\n"];

test('a layout wrapper that imports the feature file through @/ yields: no hand-rolled signal, and the import is the evidence', function () use ($kitLayout) {
    $row = usesFilesShellRow($kitLayout + ['resources/js/layouts/AppLayout.vue' => "<script setup lang=\"ts\">\nimport Layout from '@/pages/Workspace/Layout.vue';\n</script>\n\n<template>\n  <Layout><template #actions><span /></template><slot /></Layout>\n</template>\n"]);

    expect($row['signals'])->toBe([])
        ->and(array_column($row['matches'], 'detail'))->toContain('imports the feature file resources/js/pages/Workspace/Layout.vue (file name yields to uses)');
});

test('a relative import without the extension yields too', function () use ($kitLayout) {
    $row = usesFilesShellRow($kitLayout + ['resources/js/layouts/AppLayout.vue' => "<script setup lang=\"ts\">\nimport Layout from '../pages/Workspace/Layout';\n</script>\n\n<template>\n  <Layout><slot /></Layout>\n</template>\n"]);

    expect($row['signals'])->toBe([])
        ->and(array_column($row['matches'], 'detail'))->toContain('imports the feature file resources/js/pages/Workspace/Layout.vue (file name yields to uses)');
});

test('a layout wrapper that imports something else stays hand-rolled', function () use ($kitLayout) {
    $row = usesFilesShellRow($kitLayout + ['resources/js/layouts/AppLayout.vue' => "<script setup lang=\"ts\">\nimport Shell from '@/components/MyShell.vue';\n</script>\n\n<template>\n  <Shell><slot /></Shell>\n</template>\n"]);

    expect($row['signals'])->not->toBe([]);
});
