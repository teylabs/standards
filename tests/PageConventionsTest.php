<?php

declare(strict_types=1);

use Tey\Standards\PageConventions;

test('the shared page fixture permits configured directories but never component names', function () {
    $fixture = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/page-conventions.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ($fixture['paths'] as $row) {
        expect(PageConventions::validPath($row['path'], $fixture['scaffolds']))->toBe($row['valid'], $row['path']);
    }
});

test('scaffolds come from the same manifest consumed by the standalone checker', function () {
    $base = sys_get_temp_dir().'/page-conventions-'.bin2hex(random_bytes(8));
    mkdir($base);

    try {
        file_put_contents($base.'/composer.json', json_encode(['extra' => ['tey-standards' => ['pageScaffolds' => ['auth', 'settings']]]], JSON_THROW_ON_ERROR));
        expect(PageConventions::scaffolds($base))->toBe(['auth', 'settings']);

        foreach ([null, (object) [], 'auth', ['../auth'], [true], ["auth\n"]] as $invalid) {
            file_put_contents($base.'/composer.json', json_encode(['extra' => ['tey-standards' => ['pageScaffolds' => $invalid]]], JSON_THROW_ON_ERROR));
            expect(fn () => PageConventions::scaffolds($base))->toThrow(InvalidArgumentException::class);
        }
    } finally {
        unlink($base.'/composer.json');
        rmdir($base);
    }
});
