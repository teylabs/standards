<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Checks\PhpTag;
use Tey\Standards\Checks\PureTailwind;
use Tey\Standards\Configuration;
use Tey\Standards\Ignored;

const IGNORED_CSS = ".sonner-toast { color: red; }\n";
const IGNORED_PHP = "echo 'not a php tag';\n";

function ignoredConfiguration(string $base): Configuration
{
    return new Configuration(
        base: $base,
        classRoots: ['src' => 'FixtureIgnored'],
        sourceRoots: ['src', 'public', 'generated'],
        uiRoots: [],
        uiNamespaces: [],
        hostNamespaces: ['FixtureIgnored'],
        controllerRoots: [],
        routeRoots: [],
        controllerSuffix: 'Controller',
        routeDirectory: 'routes',
        viewModelRoots: [],
        viewModelBases: [],
        typedViewModel: '',
        viewModelSuffix: 'ViewModel',
        pageRoots: [],
        pageScaffolds: [],
        placements: [],
        retired: [],
        discoveryRoots: [],
        developmentNamespaces: ['Pest'],
        safeAttributes: [],
        mediaTrait: 'FixtureIgnored\\MediaBehaviour',
        mediaInterface: 'FixtureIgnored\\MediaContract',
        frontendRoots: [],
        testRoots: [],
    );
}

/** An app tree with the same violating CSS and PHP in Vite output, a generated folder and real source. */
function ignoredTree(?string $gitignore): string
{
    $base = sys_get_temp_dir().'/ignored-files-'.bin2hex(random_bytes(6));
    foreach (['public/build/assets/x.css' => IGNORED_CSS, 'public/build/x.php' => IGNORED_PHP, 'public/hot' => "http://localhost:5173\n", 'generated/out.css' => IGNORED_CSS, 'generated/Out.php' => IGNORED_PHP, 'resources/css/app.css' => IGNORED_CSS, 'src/Real.php' => IGNORED_PHP] as $path => $contents) {
        is_dir(dirname($base.'/'.$path)) || mkdir(dirname($base.'/'.$path), 0777, true);
        file_put_contents($base.'/'.$path, $contents);
    }
    if ($gitignore !== null) {
        file_put_contents($base.'/.gitignore', $gitignore);
    }
    Ignored::flush();

    return $base;
}

/** @return list<string> */
function tailwindTargets(string $base): array
{
    return array_values(array_unique(array_map(fn (array $finding): string => $finding['target'], (new PureTailwind)->violations(ignoredConfiguration($base)))));
}

/** @return list<string> */
function phpTagTargets(string $base): array
{
    return array_map(fn (array $finding): string => $finding['target'], (new PhpTag(ignoredConfiguration($base)))->violations());
}

function git(string $base, string ...$arguments): void
{
    $process = proc_open(['git', '-C', $base, '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.com', '-c', 'commit.gpgsign=false', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    expect(proc_close($process))->toBe(0);
}

test('[styling.pure-tailwind] public/build and public/hot are skipped with no .gitignore and no git; the same CSS under resources/css is still reported', function () {
    $base = ignoredTree(null);
    try {
        expect(tailwindTargets($base))->toBe(['generated/out.css', 'resources/css/app.css']);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('[styling.pure-tailwind] a folder the root .gitignore ignores is skipped without git', function () {
    $base = ignoredTree("# build output\n/generated/\n");
    try {
        expect(tailwindTargets($base))->toBe(['resources/css/app.css']);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('[php-tag] Vite output and an ignored folder are skipped; real source is still reported', function () {
    $base = ignoredTree(null);
    try {
        expect(phpTagTargets($base))->toBe(['generated/Out.php', 'src/Real.php']);
        file_put_contents($base.'/.gitignore', "generated\n");
        Ignored::flush();
        expect(phpTagTargets($base))->toBe(['src/Real.php']);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('in a git work tree, git decides: nested .gitignore files apply and a tracked file under an ignored pattern is still scanned', function () {
    $base = ignoredTree("/generated/\n");
    try {
        mkdir($base.'/src/cache', 0777, true);
        file_put_contents($base.'/src/cache/Cached.php', IGNORED_PHP);
        file_put_contents($base.'/src/.gitignore', "cache/\n");
        git($base, 'init', '-q');
        git($base, 'add', '-f', 'generated/Out.php');
        Ignored::flush();

        expect(phpTagTargets($base))->toBe(['generated/Out.php', 'src/Real.php'])
            ->and(tailwindTargets($base))->toBe(['resources/css/app.css']);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('an app tree inside a folder its parent repository ignores is not skipped wholesale', function () {
    $parent = sys_get_temp_dir().'/ignored-parent-'.bin2hex(random_bytes(6));
    mkdir($parent);
    file_put_contents($parent.'/.gitignore', "/app/\n");
    git($parent, 'init', '-q');
    $base = ignoredTree("/generated/\n");
    rename($base, $parent.'/app');
    Ignored::flush();
    try {
        expect(phpTagTargets($parent.'/app'))->toBe(['src/Real.php']);
    } finally {
        (new Filesystem)->deleteDirectory($parent);
    }
});

test('public/build is skipped even when git tracks it', function () {
    $base = ignoredTree(null);
    try {
        git($base, 'init', '-q');
        git($base, 'add', '-A');
        Ignored::flush();
        expect(phpTagTargets($base))->toBe(['generated/Out.php', 'src/Real.php']);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('the static .gitignore reader handles anchors, directory-only patterns, wildcards, ** and negation', function (string $gitignore, string $path, bool $skipped) {
    $base = sys_get_temp_dir().'/ignored-static-'.bin2hex(random_bytes(6));
    mkdir($base);
    file_put_contents($base.'/.gitignore', $gitignore);
    Ignored::flush();
    try {
        expect(Ignored::filter($base, [$path]))->toBe($skipped ? [] : [$path]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
})->with([
    'unanchored name at depth' => ["dist\n", 'resources/js/dist/a.js', true],
    'anchored does not match deeper' => ["/dist\n", 'resources/dist/a.js', false],
    'anchored matches root' => ["/dist\n", 'dist/a.js', true],
    'directory-only skips a folder' => ["out/\n", 'src/out/a.php', true],
    'directory-only spares a file' => ["out/\n", 'src/out', false],
    'wildcard' => ["*.min.css\n", 'resources/css/app.min.css', true],
    'double star' => ["docs/**/gen\n", 'docs/a/b/gen/x.css', true],
    'negation re-includes a file' => ["*.css\n!keep.css\n", 'resources/keep.css', false],
    'negation cannot re-include under an ignored folder' => ["gen/\n!gen/keep.css\n", 'gen/keep.css', true],
    'comment only' => ["# src\n", 'src/a.php', false],
    'windows separators' => ["/gen/\n", 'gen\\a.php', true],
]);
