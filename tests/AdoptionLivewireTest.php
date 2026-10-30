<?php

declare(strict_types=1);

use Tey\Standards\Adoption\App;
use Tey\Standards\Adoption\Facts;

/*
 * Livewire full-page routes and Volt::route as pages: the conventional view of a component without render() (Livewire's
 * ComponentRegistry/Finder naming and HandleComponents::getView), its route's scope, and the signed-in-pages / public-pages analyses.
 * fixtures/Adoption/livewire is a Livewire 3 + Volt app; the variants below are throwaway apps written to a temp folder.
 */

function livewireBase(): string
{
    return dirname(__DIR__).'/fixtures/Adoption/livewire';
}

/**
 * A throwaway app: each key a project-relative path, each value its contents. Removed again by livewireCleanup().
 *
 * @param  array<string, string>  $files
 */
function livewireApp(array $files): string
{
    $base = sys_get_temp_dir().'/standards-livewire-views-'.bin2hex(random_bytes(6));
    foreach ($files as $path => $contents) {
        if (! is_dir(dirname($base.'/'.$path))) {
            mkdir(dirname($base.'/'.$path), 0777, true);
        }
        file_put_contents($base.'/'.$path, $contents);
    }

    return $base;
}

function livewireCleanup(string $base): void
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($base);
}

function livewireComponent(string $namespace, string $class, string $body = ''): string
{
    return "<?php\n\nnamespace {$namespace};\n\nuse Livewire\\Component;\n\nclass {$class} extends Component\n{\n{$body}}\n";
}

/** @return array<string, string> a Livewire app whose only route is one render()-less component behind auth */
function livewireAuthOnly(string $constraint = '^3.5', ?string $config = null, string $namespace = 'App\\Livewire\\Orders', string $class = 'BoardView', string $classPath = 'app/Livewire/Orders/BoardView.php'): array
{
    return [
        'composer.json' => json_encode(['name' => 'fixture/lw', 'require' => ['livewire/livewire' => $constraint]]),
        $classPath => livewireComponent($namespace, $class),
        'routes/web.php' => "<?php\n\nuse {$namespace}\\{$class};\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::middleware('auth')->group(function () {\n    Route::get('/board', {$class}::class);\n});\n",
        ...($config === null ? [] : ['config/livewire.php' => $config]),
    ];
}

test('Livewire full-page routes and Volt::route scope their views: convention, nested kebab path, index, render() and public', function () {
    expect((new Facts(new App(livewireBase())))->viewScopes())->toBe([
        'resources/views/livewire/pricing.blade.php' => ['public'],
        'resources/views/pages/about.blade.php' => ['public'],
        'resources/views/livewire/dashboard.blade.php' => ['signed-in'],
        'resources/views/livewire/billing/invoice-list.blade.php' => ['signed-in'],
        'resources/views/livewire/reports.blade.php' => ['signed-in'],
        'resources/views/teams/roster.blade.php' => ['signed-in'],
        'resources/views/livewire/profile/edit.blade.php' => ['signed-in'],
    ]);
});

test('fail closed: a non-literal render(), a duplicate short name and the view a literal render() overrides read unknown', function () {
    $scopes = (new Facts(new App(livewireBase())))->viewScopes();

    expect($scopes)->not->toHaveKey('resources/views/livewire/live/ticker.blade.php')
        ->and($scopes)->not->toHaveKey('resources/views/livewire/admin/settings.blade.php')
        ->and($scopes)->not->toHaveKey('resources/views/livewire/account/settings.blade.php')
        ->and($scopes)->not->toHaveKey('resources/views/livewire/teams/roster.blade.php');
});

test('a render()-less Livewire component behind auth makes signed-in-pages true; its view reads signed-in', function () {
    $base = livewireApp(livewireAuthOnly());
    try {
        $facts = new Facts(new App($base));
        expect($facts->analysis('signed-in-pages'))->toBe(['value' => true, 'evidence' => 'routes/web.php:6'])
            ->and($facts->viewScopes())->toBe(['resources/views/livewire/orders/board-view.blade.php' => ['signed-in']]);
    } finally {
        livewireCleanup($base);
    }
});

test('the same app without Livewire in composer.json still has a signed-in page, but the convention is unprovable', function () {
    $files = livewireAuthOnly();
    $files['composer.json'] = json_encode(['name' => 'fixture/lw', 'require' => new stdClass]);
    $base = livewireApp($files);
    try {
        $facts = new Facts(new App($base));
        expect($facts->analysis('signed-in-pages')['value'])->toBeTrue()
            ->and($facts->viewScopes())->toBe([]);
    } finally {
        livewireCleanup($base);
    }
});

test('a Volt::route behind auth makes signed-in-pages true', function () {
    $base = livewireApp([
        'composer.json' => json_encode(['name' => 'fixture/volt', 'require' => ['livewire/volt' => '^1.6']]),
        'routes/web.php' => "<?php\n\nuse Livewire\\Volt\\Volt;\n\nVolt::route('/settings', 'settings.profile')->middleware('auth');\n",
    ]);
    try {
        expect((new Facts(new App($base)))->analysis('signed-in-pages'))->toBe(['value' => true, 'evidence' => 'routes/web.php:5']);
    } finally {
        livewireCleanup($base);
    }
});

test('config/livewire.php: literal values move the convention; a non-literal one makes it unknown', function (array $files, array $scopes) {
    $base = livewireApp($files);
    try {
        expect((new Facts(new App($base)))->viewScopes())->toBe($scopes);
    } finally {
        livewireCleanup($base);
    }
})->with([
    'literal view_path' => [livewireAuthOnly(config: "<?php\n\nreturn [\n    'view_path' => resource_path('views/screens'),\n];\n"), ['resources/views/screens/orders/board-view.blade.php' => ['signed-in']]],
    'non-literal view_path' => [livewireAuthOnly(config: "<?php\n\nreturn [\n    'view_path' => env('LIVEWIRE_VIEWS', resource_path('views/livewire')),\n];\n"), []],
    'literal class_namespace' => [livewireAuthOnly(config: "<?php\n\nreturn [\n    'class_namespace' => 'App\\\\Screens',\n];\n", namespace: 'App\\Screens\\Orders', classPath: 'app/Screens/Orders/BoardView.php'), ['resources/views/livewire/orders/board-view.blade.php' => ['signed-in']]],
    'non-literal class_namespace' => [livewireAuthOnly(config: "<?php\n\nreturn [\n    'class_namespace' => config('app.livewire_namespace'),\n];\n"), []],
    'class outside the namespace' => [livewireAuthOnly(namespace: 'App\\Screens\\Orders', classPath: 'app/Screens/Orders/BoardView.php'), []],
    'ambiguous major' => [livewireAuthOnly(constraint: '^2.0|^3.0'), []],
    'Livewire 4' => [livewireAuthOnly(constraint: '^4.0'), ['resources/views/livewire/orders/board-view.blade.php' => ['signed-in']]],
    'Livewire 2 under App\Http\Livewire' => [livewireAuthOnly(constraint: '^2.12', namespace: 'App\\Http\\Livewire\\Orders', classPath: 'app/Http/Livewire/Orders/BoardView.php'), ['resources/views/livewire/orders/board-view.blade.php' => ['signed-in']]],
    'Livewire 2 keeps index' => [livewireAuthOnly(constraint: '^2.12', namespace: 'App\\Http\\Livewire\\Orders', class: 'Index', classPath: 'app/Http/Livewire/Orders/Index.php'), ['resources/views/livewire/orders/index.blade.php' => ['signed-in']]],
    'Livewire 3 drops index' => [livewireAuthOnly(class: 'Index', classPath: 'app/Livewire/Orders/Index.php'), ['resources/views/livewire/orders.blade.php' => ['signed-in']]],
]);

test('a Livewire::component() alias naming the class makes its conventional view unknown', function () {
    $files = livewireAuthOnly();
    $files['app/Providers/AppServiceProvider.php'] = "<?php\n\nnamespace App\\Providers;\n\nuse App\\Livewire\\Orders\\BoardView;\nuse Livewire\\Livewire;\n\nclass AppServiceProvider\n{\n    public function boot(): void\n    {\n        Livewire::component('board', BoardView::class);\n    }\n}\n";
    $base = livewireApp($files);
    try {
        expect((new Facts(new App($base)))->viewScopes())->toBe([]);
    } finally {
        livewireCleanup($base);
    }
});

test('Volt::route maps to the first mounted folder that has the file; no provider or a non-literal mount reads unknown', function (?string $provider, array $scopes) {
    $base = livewireApp([
        'composer.json' => json_encode(['name' => 'fixture/volt', 'require' => ['livewire/volt' => '^1.6']]),
        'routes/web.php' => "<?php\n\nuse Livewire\\Volt\\Volt;\n\nRoute::middleware('auth')->group(function () {\n    Volt::route('/settings', 'settings.profile');\n});\n",
        'resources/views/pages/settings/profile.blade.php' => "<div></div>\n",
        ...($provider === null ? [] : ['app/Providers/VoltServiceProvider.php' => "<?php\n\nnamespace App\\Providers;\n\nuse Livewire\\Volt\\Volt;\n\nclass VoltServiceProvider\n{\n    public function boot(): void\n    {\n        Volt::mount({$provider});\n    }\n}\n"]),
    ]);
    try {
        expect((new Facts(new App($base)))->viewScopes())->toBe($scopes);
    } finally {
        livewireCleanup($base);
    }
})->with([
    'stub mount' => ["[\n            config('livewire.view_path', resource_path('views/livewire')),\n            resource_path('views/pages'),\n        ]", ['resources/views/pages/settings/profile.blade.php' => ['signed-in']]],
    'empty mount (Volt defaults)' => ['', ['resources/views/pages/settings/profile.blade.php' => ['signed-in']]],
    'mount without pages' => ["[resource_path('views/livewire')]", []],
    'non-literal mount' => ['$this->paths()', []],
    'no provider' => [null, []],
]);

/** @return array<string, string> livewireAuthOnly() with Livewire only through flux and volt in composer.json, and `$lock` as composer.lock */
function livewireTransitive(?string $lock): array
{
    $files = livewireAuthOnly();
    $files['composer.json'] = json_encode(['name' => 'fixture/lw', 'require' => ['livewire/flux' => '^2.1', 'livewire/volt' => '^1.7']]);
    if ($lock !== null) {
        $files['composer.lock'] = $lock;
    }

    return $files;
}

function livewireLock(string $version, string $section = 'packages'): string
{
    return json_encode([$section => [['name' => 'livewire/flux', 'version' => 'v2.1.1'], ['name' => 'livewire/livewire', 'version' => $version]], ($section === 'packages' ? 'packages-dev' : 'packages') => []]);
}

test('Livewire only through flux and volt: the locked major proves the convention, and the auth route scopes the view signed-in', function (string $section) {
    $base = livewireApp(livewireTransitive(livewireLock('v3.6.4', $section)));
    try {
        $facts = new Facts(new App($base));
        expect($facts->analysis('signed-in-pages'))->toBe(['value' => true, 'evidence' => 'routes/web.php:6'])
            ->and($facts->viewScopes())->toBe(['resources/views/livewire/orders/board-view.blade.php' => ['signed-in']]);
    } finally {
        livewireCleanup($base);
    }
})->with(['packages', 'packages-dev']);

test('Livewire only through flux and volt: no lock, no lock entry or an unparsable locked version is unprovable', function (?string $lock) {
    $base = livewireApp(livewireTransitive($lock));
    try {
        expect((new Facts(new App($base)))->viewScopes())->toBe([]);
    } finally {
        livewireCleanup($base);
    }
})->with([
    'no composer.lock' => [null],
    'no livewire entry' => [json_encode(['packages' => [['name' => 'livewire/flux', 'version' => 'v2.1.1']], 'packages-dev' => []])],
    'malformed version' => [livewireLock('dev-main')],
    'version with no major' => [livewireLock('v.x')],
]);

test('composer.json wins when it names exactly one major; an ambiguous constraint falls back to the lock', function (string $constraint, string $locked, array $scopes) {
    $files = livewireAuthOnly($constraint);
    $files['composer.lock'] = livewireLock($locked);
    $base = livewireApp($files);
    try {
        expect((new Facts(new App($base)))->viewScopes())->toBe($scopes);
    } finally {
        livewireCleanup($base);
    }
})->with([
    // Livewire 2 renders resources/views/livewire too, but defaults the namespace to App\Http\Livewire: App\Livewire\... is outside it.
    'one major in composer.json beats the lock' => ['^2.12', 'v3.6.4', []],
    'agreeing constraint and lock' => ['^3.5', 'v3.6.4', ['resources/views/livewire/orders/board-view.blade.php' => ['signed-in']]],
    'ambiguous constraint, lock decides' => ['^2.12|^3.0', 'v3.6.4', ['resources/views/livewire/orders/board-view.blade.php' => ['signed-in']]],
    'ambiguous constraint, lock on 2' => ['^2.12|^3.0', 'v2.12.6', []],
]);
