<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Enforcement\CheckReport;
use Tey\Standards\Enforcement\Maker;
use Tey\Standards\Patterns\TreeCatalog;

/** A copy of fixtures/App whose configuration names roots that do not exist yet: a fresh module with no controllers, ViewModels or pages. */
function appWithMissingRoots(): string
{
    $base = sys_get_temp_dir().'/standards-roots-'.bin2hex(random_bytes(6));
    (new Filesystem)->copyDirectory(dirname(__DIR__).'/fixtures/App', $base);
    mkdir($base.'/vendor');
    file_put_contents($base.'/vendor/autoload.php', '<?php require '.var_export(dirname(__DIR__).'/vendor/autoload.php', true).'; require __DIR__.\'/../tests/Support/framework.php\'; spl_autoload_register(function (string $class) { $file = __DIR__."/../app/".str_replace("\\\\", "/", substr($class, 4)).".php"; if (str_starts_with($class, "App\\\\") && is_file($file)) { require_once $file; } });');
    require_once $base.'/tests/Support/framework.php';
    spl_autoload_register(function (string $class) use ($base): void {
        $file = $base.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (str_starts_with($class, 'App\\') && is_file($file)) {
            require_once $file;
        }
    });
    $configuration = $base.'/tests/Arch/configuration.php';
    $contents = (string) file_get_contents($configuration);
    $contents = str_replace("controllerRoots: ['app/Http/Controllers'],", "controllerRoots: ['app/Http/Controllers', 'app/Billing/Http/Controllers'],", $contents);
    $contents = str_replace("viewModelRoots: ['app/UI', 'app/ViewModels*'],", "viewModelRoots: ['app/UI', 'app/ViewModels*', 'app/Billing/ViewModels'],", $contents);
    $contents = str_replace("pageRoots: ['resources/js/pages'],", "pageRoots: ['resources/js/pages', 'resources/js/billing'],", $contents);
    file_put_contents($configuration, $contents);
    // Even the conventional controller root is missing in a fresh app that has not made its first controller.
    (new Filesystem)->deleteDirectory($base.'/app/Http/Controllers');

    return $base;
}

test('a configured root that does not exist is skipped by every rule and reported once, as a configuration advisory', function () {
    $base = appWithMissingRoots();

    try {
        expect(file_get_contents($base.'/tests/Arch/configuration.php'))->toContain("'app/Billing/Http/Controllers'")->toContain("'app/Billing/ViewModels'")->toContain("'resources/js/billing'")
            ->and(is_dir($base.'/app/Http/Controllers'))->toBeFalse();
        $report = CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-28'), TreeCatalog::shipped());

        expect($report['violations'])->toBe([], 'no rule errors on a missing root (toast and rule 21 used to)')
            ->and(array_column(array_filter($report['advisories'], fn (array $finding): bool => $finding['rule'] === 'configuration'), 'target'))->toBe(['app/Billing/Http/Controllers', 'app/Billing/ViewModels', 'app/Http/Controllers', 'resources/js/billing'])
            ->and($report['exit_code'])->toBe(0);
        // A --changed run is about the changed files: no configuration advisory there.
        $changed = CheckReport::build($base, 'tests/Arch/configuration.php', ['routes/web.php'], 'changed', new DateTimeImmutable('2026-10-28'), TreeCatalog::shipped());
        expect($changed['violations'])->toBe([])->and(array_column($changed['advisories'], 'rule'))->not->toContain('configuration');

        // Once a module gets its first files, its roots are checked as any other.
        (new Maker($base, TreeCatalog::shipped(), kit: true))->make('page', 'Posts', 'index');
        $after = CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-28'), TreeCatalog::shipped());
        expect($after['violations'])->toBe([])
            ->and(array_column(array_filter($after['advisories'], fn (array $finding): bool => $finding['rule'] === 'configuration'), 'target'))->not->toContain('app/Http/Controllers');
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});
