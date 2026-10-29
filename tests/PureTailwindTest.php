<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\AssertionFailedError;
use Tey\Standards\Checks\PureTailwind;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;
use Tey\Standards\Ratchet;

function tailwindConfiguration(string $base, array $allowlist = []): Configuration
{
    return new Configuration(
        base: str_starts_with($base, '/') ? $base : dirname(__DIR__).'/fixtures/PureTailwind/'.$base,
        classRoots: ['src' => 'FixtureTailwind'],
        sourceRoots: ['src'],
        uiRoots: ['src/UI'],
        uiNamespaces: ['FixtureTailwind\\UI'],
        hostNamespaces: ['FixtureTailwind'],
        controllerRoots: ['src/Http/Controllers'],
        routeRoots: ['routes'],
        controllerSuffix: 'Controller',
        routeDirectory: 'routes',
        viewModelRoots: ['src/UI'],
        viewModelBases: [],
        typedViewModel: '',
        viewModelSuffix: 'ViewModel',
        pageRoots: ['resources/js/pages'],
        pageScaffolds: [],
        placements: [],
        retired: [],
        discoveryRoots: ['src'],
        developmentNamespaces: ['Pest'],
        safeAttributes: [],
        mediaTrait: 'FixtureTailwind\\MediaBehaviour',
        mediaInterface: 'FixtureTailwind\\MediaContract',
        allowlist: $allowlist,
    );
}

/** @return list<array{target: string, check: string, line?: int}> without hints */
function tailwindFindings(string $case): array
{
    return array_map(fn (array $finding): array => array_diff_key($finding, ['hint' => true]), (new PureTailwind)->violations(tailwindConfiguration($case)));
}

test('[styling.pure-tailwind] a <style> block in a .vue file fails, with its line', function () {
    expect(tailwindFindings('style-block'))->toBe([
        ['target' => 'resources/js/pages/Report.vue', 'check' => 'style block in a .vue file', 'line' => 9],
    ]);
});

test('[styling.pure-tailwind] a static style attribute fails in .vue and Blade templates; a commented-out one does not', function () {
    expect(tailwindFindings('static-style'))->toBe([
        ['target' => 'resources/js/pages/Report.vue', 'check' => 'inline style attribute', 'line' => 3],
        ['target' => 'resources/views/welcome.blade.php', 'check' => 'inline style attribute', 'line' => 3],
    ]);
});

test('[styling.pure-tailwind] a :style binding of custom properties only passes, and so does a Blade style of custom properties', function () {
    expect(tailwindFindings('custom-properties'))->toBe([]);
});

test('[styling.pure-tailwind] a :style binding with a real CSS property, or one it cannot read as custom properties, fails', function () {
    expect(tailwindFindings('real-property'))->toBe([
        ['target' => 'resources/js/pages/Progress.vue', 'check' => ':style binds a CSS property other than a custom property', 'line' => 7],
        ['target' => 'resources/js/pages/Progress.vue', 'check' => ':style binds a CSS property other than a custom property', 'line' => 8],
    ]);
});

test('[styling.pure-tailwind] kit-owned files are skipped: the Kit tree and an unedited file tey/mod records', function () {
    expect(tailwindFindings('kit-owned'))->toBe([]);
});

test('[styling.pure-tailwind] an edited kit-generated file is checked again', function () {
    expect(tailwindFindings('kit-edited'))->toBe([
        ['target' => 'resources/js/pages/Workspace/Page.vue', 'check' => 'inline style attribute', 'line' => 2],
    ]);
});

test('[styling.pure-tailwind] @font-face passes', function () {
    expect(tailwindFindings('font-face'))->toBe([]);
});

test('[styling.pure-tailwind] Tailwind directives, @theme, @utility, @layer base rules and :root/.dark tokens pass', function () {
    expect(tailwindFindings('theme'))->toBe([]);
});

test('[styling.pure-tailwind] a CSS class rule fails, as do non-token declarations on :root, class rules in @layer and loose at-rules', function () {
    expect(tailwindFindings('class-rule'))->toBe([
        ['target' => 'resources/css/app.css', 'check' => 'declaration other than a custom property on :root or .dark', 'line' => 5],
        ['target' => 'resources/css/app.css', 'check' => 'custom CSS rule .btn-primary', 'line' => 8],
        ['target' => 'resources/css/app.css', 'check' => 'custom CSS rule .card', 'line' => 13],
        ['target' => 'resources/css/app.css', 'check' => 'at-rule @keyframes outside @theme', 'line' => 16],
    ]);
});

test('[styling.pure-tailwind] Filament theme CSS passes', function () {
    expect(tailwindFindings('filament'))->toBe([])
        ->and(PureTailwind::isFilamentTheme('resources/css/panel.css', "@import '../../vendor/filament/filament/resources/css/theme.css';\n.x { color: red; }"))->toBeTrue()
        ->and(PureTailwind::isFilamentTheme('resources/css/app.css', ".x { color: red; }"))->toBeFalse();
});

test('[styling.pure-tailwind] fails closed: a file it cannot parse is a finding, not a pass', function () {
    expect(array_map(fn (array $finding): array => [$finding['target'], $finding['check']], tailwindFindings('unparsable')))->toBe([
        ['resources/css/app.css', 'could not parse the file'],
        ['resources/js/pages/Broken.vue', 'could not parse the file'],
    ]);
});

test('[styling.pure-tailwind] fails closed: a file it cannot read is a finding, not a pass', function () {
    $base = sys_get_temp_dir().'/pure-tailwind-'.bin2hex(random_bytes(6));
    mkdir($base.'/resources/css', 0777, true);
    file_put_contents($base.'/resources/css/app.css', ".x { color: red; }\n");
    chmod($base.'/resources/css/app.css', 0000);

    try {
        if (is_readable($base.'/resources/css/app.css')) {
            $this->markTestSkipped('Running as a user that reads mode-0000 files (root); unreadable files cannot be simulated.');
        }
        expect(tailwindFindings($base))->toBe([['target' => 'resources/css/app.css', 'check' => 'could not read the file']]);
    } finally {
        chmod($base.'/resources/css/app.css', 0644);
        (new Filesystem)->deleteDirectory($base);
    }
});

test('[styling.pure-tailwind] an allowlist entry needs a reason and an expiry; a complete one permits exactly its finding', function () {
    $entry = ['rule' => PureTailwind::RULE, 'target' => 'resources/js/pages/Report.vue', 'check' => 'style block in a .vue file', 'date' => '2026-10-29'];

    expect(fn () => new Ratchet([$entry]))->toThrow(InvalidArgumentException::class, 'non-empty [reason]')
        ->and(fn () => new Ratchet([[...$entry, 'reason' => ' ', 'expires' => '2099-12-31']]))->toThrow(InvalidArgumentException::class, 'non-empty [reason]')
        ->and(fn () => (new Conformance(tailwindConfiguration('style-block', [[...$entry, 'reason' => 'legacy print view']])))->assert(PureTailwind::RULE))
        ->toThrow(AssertionFailedError::class, 'exception has no expiry');

    (new Conformance(tailwindConfiguration('style-block', [[...$entry, 'reason' => 'legacy print view', 'expires' => '2099-12-31']])))->assert(PureTailwind::RULE);
    expect(fn () => (new Conformance(tailwindConfiguration('static-style', [[...$entry, 'reason' => 'legacy print view', 'expires' => '2099-12-31']])))->assert(PureTailwind::RULE))
        ->toThrow(AssertionFailedError::class, 'violation: resources/js/pages/Report.vue | inline style attribute');
});

test('[styling.pure-tailwind] the rule is registered as a required conformance rule', function () {
    expect(Conformance::TESTS)->toHaveKey(PureTailwind::RULE)
        ->and(Tey\Standards\Enforcement\Gate::OPTIONAL)->not->toContain(PureTailwind::RULE);
});

test('[styling.pure-tailwind] the package generators emit no styling the rule rejects', function () {
    $stubs = dirname(__DIR__).'/resources/stubs';
    $checked = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stubs, FilesystemIterator::SKIP_DOTS)) as $file) {
        $name = $file->getFilename();
        $source = (string) file_get_contents($file->getPathname());
        $findings = match (true) {
            str_contains($name, '.vue') => PureTailwind::vue($source),
            str_contains($name, '.blade') => PureTailwind::blade($source),
            str_contains($name, '.css') => PureTailwind::css($source),
            default => null,
        };
        if ($findings !== null) {
            $checked++;
            expect($findings)->toBe([], $file->getPathname());
        }
    }

    expect($checked)->toBeGreaterThan(0);
});
