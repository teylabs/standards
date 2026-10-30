<?php

declare(strict_types=1);

use Tey\Standards\Adoption\App;
use Tey\Standards\Adoption\Inventory;
use Tey\Standards\Adoption\Scanner;

/*
 * A `file-name` hand-rolled hit yields, per file, when that same file satisfies the surface's `uses` (imports or renders a
 * `uses.components` entry, or references a `uses.classes` entry). Other signal kinds keep "hand-rolled wins". Throwaway apps in a temp folder.
 */

/** @param array<string, string> $files */
function yieldApp(array $files): string
{
    $base = sys_get_temp_dir().'/standards-file-name-yield-'.bin2hex(random_bytes(6));
    $files += ['composer.json' => json_encode(['name' => 'fixture/yield', 'require' => ['tey/kit' => '^1.0']])];
    foreach ($files as $path => $contents) {
        if (! is_dir(dirname($base.'/'.$path))) {
            mkdir(dirname($base.'/'.$path), 0777, true);
        }
        file_put_contents($base.'/'.$path, $contents);
    }

    return $base;
}

function yieldCleanup(string $base): void
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($base);
}

/** @param list<array<string, mixed>> $handRolled */
function yieldRow(array $files, array $handRolled = [['kind' => 'file-name', 'globs' => ['*Banner*']]]): array
{
    $base = yieldApp($files);
    try {
        $inventory = Inventory::fromArray(['format' => 1, 'package' => ['composer' => 'tey/kit', 'alias' => '@/components/Kit'], 'facts' => [], 'nonSurfaces' => [], 'surfaces' => [[
            'id' => 'feedback.alert', 'layer' => 'ui', 'group' => 'feedback', 'label' => 'Alerts', 'feature' => 'core', 'applies' => true,
            'uses' => ['components' => ['Alert']], 'handRolled' => $handRolled, 'docs' => 'docs/x.md', 'status' => 'stable',
        ]]], 'yield.json');
        $app = new App($base, App::bare($base));

        return (new Scanner($app, [$inventory], null, new DateTimeImmutable('2026-10-29')))->report()['rows'][0];
    } finally {
        yieldCleanup($base);
    }
}

const YIELD_IMPORTS_ALERT = "<script setup lang=\"ts\">\nimport { Alert } from '@/components/Kit';\n</script>\n\n<template>\n  <div class=\"beta\">Beta</div>\n</template>\n";
const YIELD_OWN_BANNER = "<template>\n  <div class=\"beta\">Beta</div>\n</template>\n";

test('a *Banner* file that imports kit Alert yields: the row reads adopted, citing that file', function () {
    $row = yieldRow(['resources/js/components/chrome/BetaBanner.vue' => YIELD_IMPORTS_ALERT]);

    expect($row['status'])->toBe('adopted')
        ->and($row['signals'])->toBe([])
        ->and(array_column($row['evidence'], 'file'))->toBe(['resources/js/components/chrome/BetaBanner.vue'])
        ->and($row['evidence'][0]['line'])->toBe(2);
});

test('a *Banner* file that renders <Alert> yields, cited once', function () {
    $row = yieldRow(['resources/js/components/chrome/BetaBanner.vue' => "<template>\n  <Alert>Beta</Alert>\n</template>\n"]);

    expect($row['status'])->toBe('adopted')
        ->and(array_column($row['evidence'], 'file'))->toBe(['resources/js/components/chrome/BetaBanner.vue']);
});

test('the same name without the import stays hand-rolled', function () {
    $row = yieldRow(['resources/js/components/chrome/BetaBanner.vue' => YIELD_OWN_BANNER]);

    expect($row['status'])->toBe('hand-rolled')
        ->and(array_column($row['evidence'], 'file'))->toBe(['resources/js/components/chrome/BetaBanner.vue']);
});

test('two files, one yielding and one not: hand-rolled with only the non-yielding file as evidence', function () {
    $row = yieldRow([
        'resources/js/components/chrome/BetaBanner.vue' => YIELD_IMPORTS_ALERT,
        'resources/js/components/chrome/PromoBanner.vue' => YIELD_OWN_BANNER,
    ]);

    expect($row['status'])->toBe('hand-rolled')
        ->and(array_column($row['evidence'], 'file'))->toBe(['resources/js/components/chrome/PromoBanner.vue'])
        ->and(array_column($row['matches'], 'file'))->toBe(['resources/js/components/chrome/BetaBanner.vue']);
});

test('only file-name yields: a template-tag hit in a file that also imports the kit component stays hand-rolled', function () {
    $row = yieldRow(
        ['resources/js/pages/Dashboard/Page.vue' => "<script setup lang=\"ts\">\nimport { Alert } from '@/components/Kit';\n</script>\n\n<template>\n  <Alert />\n  <div role=\"alert\">x</div>\n</template>\n"],
        [['kind' => 'template-tag', 'tags' => ['div']]],
    );

    expect($row['status'])->toBe('hand-rolled')
        ->and(array_column($row['signals'], 'file'))->toBe(['resources/js/pages/Dashboard/Page.vue']);
});

test('kit-owned files stay out: a *Banner* under components/Kit neither fires nor yields', function () {
    $row = yieldRow(['resources/js/components/Kit/Banner.vue' => YIELD_OWN_BANNER]);

    expect($row['status'])->toBe('missing')
        ->and($row['signals'])->toBe([]);
});
