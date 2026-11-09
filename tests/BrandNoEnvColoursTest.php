<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;

/** A Configuration over a scratch app. */
function brandConfiguration(string $base): Configuration
{
    return new Configuration(
        base: $base,
        classRoots: [], sourceRoots: [], uiRoots: [], uiNamespaces: [], hostNamespaces: [],
        controllerRoots: [], routeRoots: [], controllerSuffix: 'Controller', routeDirectory: 'routes',
        viewModelRoots: [], viewModelBases: [], typedViewModel: '', viewModelSuffix: 'ViewModel',
        pageRoots: [], pageScaffolds: [], placements: [], retired: [], discoveryRoots: [],
        developmentNamespaces: [], safeAttributes: [], mediaTrait: '', mediaInterface: '',
    );
}

/**
 * Rule brand.no-env-colours on a scratch app with the given files. With $tracked, the scratch app is its own git repository
 * and only those paths are added to its index; the rest stay untracked.
 *
 * @param  array<string, string>  $files
 * @param  ?list<string>  $tracked
 * @return list<string>
 */
function brandFindings(array $files, ?array $tracked = null): array
{
    $base = dirname(__DIR__).'/.phpunit.cache/brand-'.bin2hex(random_bytes(5));
    mkdir($base, 0777, true);
    foreach ($files as $path => $contents) {
        is_dir(dirname($base.'/'.$path)) || mkdir(dirname($base.'/'.$path), 0777, true);
        file_put_contents($base.'/'.$path, $contents);
    }
    try {
        if ($tracked !== null) {
            (new Process(['git', 'init', '-q'], $base))->mustRun();
            $tracked === [] || (new Process(['git', 'add', '-f', '--', ...$tracked], $base))->mustRun();
        }

        return array_map(fn (array $v): string => $v['target'].':'.$v['line'].' '.$v['check'].(($v['advisory'] ?? false) ? ' [advisory]' : ''), (new Conformance(brandConfiguration($base)))->violations('brand.no-env-colours'));
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
}

const BRAND_FIX = '; run `php artisan kit:brand:migrate`, commit config/brand.php, then delete the env lines. Env keeps only secrets and per-environment values; this warning becomes an error in the tey/kit 0.16 era. [advisory]';

const BRAND_ENV = "APP_NAME=Acme\nKIT_BRAND_NAME=\"Acme Widgets\"\n# KIT_BRAND_MUTED=#999999\nexport KIT_BRAND_ACCENT=#3355ff\nDB_PASSWORD=\n";

test('brand.no-env-colours warns on each KIT_BRAND_* key in .env.example, naming the key and the file', function () {
    expect(brandFindings(['.env.example' => BRAND_ENV]))->toBe([
        '.env.example:2 .env.example sets brand key KIT_BRAND_NAME: brand settings belong in config/brand.php, not env'.BRAND_FIX,
        '.env.example:4 .env.example sets brand key KIT_BRAND_ACCENT: brand settings belong in config/brand.php, not env'.BRAND_FIX,
    ]);
});

test('brand.no-env-colours passes env files without KIT_BRAND_* keys', function () {
    expect(brandFindings(['.env.example' => "APP_NAME=Acme\n# KIT_BRAND_ACCENT=#3355ff\nMY_KIT_BRAND_ACCENT=x\n", '.env.testing' => "APP_ENV=testing\n"], ['.env.example', '.env.testing']))->toBe([]);
});

test('brand.no-env-colours reads tracked .env.* files and ignores .env and untracked env files', function () {
    $files = ['.env' => BRAND_ENV, '.env.testing' => "KIT_BRAND_ACCENT=#3355ff\n", '.env.local' => "KIT_BRAND_ACCENT=#3355ff\n"];

    expect(brandFindings($files, ['.env', '.env.testing']))->toBe([
        '.env.testing:1 .env.testing sets brand key KIT_BRAND_ACCENT: brand settings belong in config/brand.php, not env'.BRAND_FIX,
    ])->and(brandFindings($files, []))->toBe([]);
});

test('brand.no-env-colours flags a config file that reads a brand value with env()', function () {
    $kit = "<?php\n\nreturn [\n    // env('KIT_BRAND_MUTED')\n    'brand' => [\n        'accent' => env('KIT_BRAND_ACCENT', '#3355ff'),\n        'name' => \\env(\"KIT_BRAND_NAME\"),\n        'app' => env('APP_NAME'),\n    ],\n];\n";

    expect(brandFindings(['config/kit.php' => $kit, 'config/brand.php' => "<?php\n\nreturn ['accent' => env('KIT_BRAND_ACCENT')];\n"]))->toBe([
        "config/kit.php:6 config/kit.php reads brand key KIT_BRAND_ACCENT with env('KIT_BRAND_ACCENT'): brand settings belong in config/brand.php as committed values".BRAND_FIX,
        "config/kit.php:7 config/kit.php reads brand key KIT_BRAND_NAME with env('KIT_BRAND_NAME'): brand settings belong in config/brand.php as committed values".BRAND_FIX,
        "config/brand.php:3 config/brand.php reads brand key KIT_BRAND_ACCENT with env('KIT_BRAND_ACCENT'): brand settings belong in config/brand.php as committed values".BRAND_FIX,
    ]);
});

test('brand.no-env-colours passes a clean config: committed brand values, and env() only for other keys', function () {
    expect(brandFindings([
        'config/brand.php' => "<?php\n\n/* Was env('KIT_BRAND_ACCENT'). */\nreturn ['accent' => '#3355ff', 'name' => 'Acme Widgets'];\n",
        'config/kit.php' => "<?php\n\nreturn ['key' => env('KIT_LICENSE_KEY'), 'label' => 'KIT_BRAND_ACCENT'];\n",
        'config/app.php' => "<?php\n\nreturn ['accent' => env('KIT_BRAND_ACCENT')];\n",
    ]))->toBe([]);
});

test('brand.no-env-colours warns only: the conformance test skips, never fails', function () {
    $base = dirname(__DIR__).'/.phpunit.cache/brand-'.bin2hex(random_bytes(5));
    mkdir($base, 0777, true);
    file_put_contents($base.'/.env.example', BRAND_ENV);
    try {
        expect(fn () => (new Conformance(brandConfiguration($base)))->assert('brand.no-env-colours'))
            ->toThrow(PHPUnit\Framework\SkippedWithMessageException::class, 'Rule [brand.no-env-colours] warns only (2 findings).');
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});
