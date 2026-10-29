<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Conformance;
use Tey\Standards\Configuration;
use Tey\Standards\Enforcement\Gate;

/** A scratch app whose AppServiceProvider::boot() runs the given statements. */
function destructiveApp(string $boot, string $imports = "use Illuminate\\Support\\Facades\\DB;\n"): string
{
    $base = sys_get_temp_dir().'/standards-destructive-'.bin2hex(random_bytes(6));
    mkdir($base.'/app/Providers', 0777, true);
    file_put_contents($base.'/app/Providers/AppServiceProvider.php', "<?php\n\nnamespace App\\Providers;\n\n{$imports}use Illuminate\\Support\\ServiceProvider;\n\n"
        ."final class AppServiceProvider extends ServiceProvider\n{\n    public function boot(): void\n    {\n        {$boot}\n    }\n}\n");
    DestructiveTemp::$dirs[] = $base;

    return $base;
}

/** @param list<string> $optional */
function destructiveConfiguration(string $base, array $optional = ['destructive-db']): Configuration
{
    return new Configuration(...[
        'base' => $base, 'classRoots' => ['app' => 'App'], 'sourceRoots' => ['app'], 'uiRoots' => ['app/UI'], 'uiNamespaces' => ['App\\UI'], 'hostNamespaces' => ['App'],
        'controllerRoots' => ['app/Http/Controllers'], 'routeRoots' => ['routes'], 'controllerSuffix' => 'Controller', 'routeDirectory' => 'routes', 'viewModelRoots' => ['app/UI'], 'viewModelBases' => [],
        'typedViewModel' => '', 'viewModelSuffix' => 'ViewModel', 'pageRoots' => ['pages'], 'pageScaffolds' => ['pages'], 'placements' => [], 'retired' => [], 'discoveryRoots' => ['app'],
        'developmentNamespaces' => ['Pest'], 'safeAttributes' => [], 'mediaTrait' => 'X\\MediaTrait', 'mediaInterface' => 'X\\MediaInterface', 'optionalRules' => $optional,
    ]);
}

/** @return list<string> the findings' checks */
function destructiveChecks(string $base, array $optional = ['destructive-db']): array
{
    return array_column((new Conformance(destructiveConfiguration($base, $optional)))->violations('destructive-db'), 'check');
}

final class DestructiveTemp
{
    /** @var list<string> */
    public static array $dirs = [];
}

afterEach(function () {
    foreach (DestructiveTemp::$dirs as $directory) {
        (new Filesystem)->deleteDirectory($directory);
    }
    DestructiveTemp::$dirs = [];
});

test('a guard that is true outside testing passes', function (string $boot, string $imports) {
    expect(destructiveChecks(destructiveApp($boot, $imports)))->toBe([]);
})->with([
    'runningUnitTests' => ['DB::prohibitDestructiveCommands(! $this->app->runningUnitTests());', "use Illuminate\\Support\\Facades\\DB;\n"],
    'app() helper' => ['DB::prohibitDestructiveCommands(! app()->runningUnitTests());', "use Illuminate\\Support\\Facades\\DB;\n"],
    'environment testing' => ["DB::prohibitDestructiveCommands(! \$this->app->environment('testing'));", "use Illuminate\\Support\\Facades\\DB;\n"],
    'with the config override' => ["DB::prohibitDestructiveCommands(! \$this->app->runningUnitTests() && ! config('database.allow_destructive'));", "use Illuminate\\Support\\Facades\\DB;\n"],
    'fully qualified facade' => ['\Illuminate\Support\Facades\DB::prohibitDestructiveCommands(! app()->runningUnitTests());', ''],
    'root alias' => ['\DB::prohibitDestructiveCommands(! app()->runningUnitTests());', ''],
]);

test('a provider without the call fails', function () {
    expect(destructiveChecks(destructiveApp('//')))->toBe(['no DB::prohibitDestructiveCommands() guard in a service provider boot()']);
});

test('a production-only guard fails', function (string $boot) {
    expect(destructiveChecks(destructiveApp($boot)))->toBe(['prohibitDestructiveCommands() guard only covers production; prohibit everywhere outside testing']);
})->with([
    'isProduction' => ['DB::prohibitDestructiveCommands($this->app->isProduction());'],
    'environment production' => ["DB::prohibitDestructiveCommands(app()->environment('production'));"],
]);

test('a bare call fails, since it prohibits RefreshDatabase in tests too', function (string $boot) {
    expect(destructiveChecks(destructiveApp($boot)))->toBe(['prohibitDestructiveCommands() without a guard also prohibits in tests, where RefreshDatabase runs migrate:fresh']);
})->with([['DB::prohibitDestructiveCommands();'], ['DB::prohibitDestructiveCommands(true);']]);

test('env() in the guard fails', function () {
    expect(destructiveChecks(destructiveApp("DB::prohibitDestructiveCommands(! app()->runningUnitTests() && ! env('ALLOW_DESTRUCTIVE_DB'));")))
        ->toBe(['prohibitDestructiveCommands() guard reads env() directly; read a config key backed by env in config/*.php']);
});

test('an unrecognised guard fails', function () {
    expect(destructiveChecks(destructiveApp("DB::prohibitDestructiveCommands(! config('database.allow_destructive'));")))
        ->toBe(['prohibitDestructiveCommands() guard is not recognised as true outside testing']);
});

test('the rule is off by default', function () {
    $base = destructiveApp('//');

    expect(destructiveChecks($base, []))->toBe([])
        ->and(in_array('destructive-db', Gate::OPTIONAL, true))->toBeTrue()
        ->and(Conformance::TESTS)->toHaveKey('destructive-db');
});
