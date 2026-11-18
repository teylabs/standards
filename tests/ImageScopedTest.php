<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;

/** @param array<string, string> $files */
function imageScopedFindings(array $files): array
{
    $base = dirname(__DIR__).'/.phpunit.cache/image-scoped-'.bin2hex(random_bytes(5));
    foreach ($files as $path => $contents) {
        is_dir(dirname($base.'/'.$path)) || mkdir(dirname($base.'/'.$path), 0777, true);
        file_put_contents($base.'/'.$path, $contents);
    }
    try {
        $config = new Configuration(
            base: $base, classRoots: ['app' => 'App'], sourceRoots: ['app'], uiRoots: [], uiNamespaces: [], hostNamespaces: ['App'],
            controllerRoots: [], routeRoots: [], controllerSuffix: 'Controller', routeDirectory: 'routes',
            viewModelRoots: [], viewModelBases: [], typedViewModel: '', viewModelSuffix: 'ViewModel',
            pageRoots: [], pageScaffolds: [], placements: [], retired: [], discoveryRoots: [],
            developmentNamespaces: [], safeAttributes: [], mediaTrait: '', mediaInterface: '',
        );

        return array_map(fn (array $v): string => basename($v['target']).':'.$v['line'].' '.$v['check'], (new Conformance($config))->violations('media.image-scoped'));
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
}

test('media.image-scoped warns on transformUsing and extend registered once on the scoped image manager', function () {
    expect(imageScopedFindings(['app/Providers/AppServiceProvider.php' => <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Image;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Image::transformUsing('kit-imagick', StackBelow::class, new StackBelowHandler);
        Image::extend('mine', fn () => new MyDriver);
        app('image')->transformUsing('gd', Tint::class, fn ($image) => $image);
        $this->app['image']->extend('other', fn () => new OtherDriver);
        $this->app->extend(Cache::class, fn ($cache) => $cache);
    }
}
PHP]))->toBe([
        'AppServiceProvider.php:12 transformUsing() on the image manager outside a resolving callback',
        'AppServiceProvider.php:13 extend() on the image manager outside a resolving callback',
        'AppServiceProvider.php:14 transformUsing() on the image manager outside a resolving callback',
        'AppServiceProvider.php:15 extend() on the image manager outside a resolving callback',
    ]);
});

test('media.image-scoped is quiet inside callAfterResolving, afterResolving and resolving, and on unrelated extend calls', function () {
    expect(imageScopedFindings(['app/Providers/AppServiceProvider.php' => <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Image\ImageManager;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->callAfterResolving('image', function (ImageManager $images): void {
            $images->transformUsing('kit-imagick', StackBelow::class, new StackBelowHandler);
            $images->extend('mine', fn () => new MyDriver);
        });
        $this->app->afterResolving('image', fn (ImageManager $images) => $images->transformUsing('gd', Tint::class, fn ($image) => $image));
        $this->app->resolving('image', fn ($images) => $images->extend('other', fn () => new OtherDriver));
        $this->app->extend(Cache::class, fn ($cache) => $cache);
        Auth::extend('token', fn () => new TokenGuard);
    }
}
PHP, 'app/Support/Collection.php' => "<?php\n\nnamespace App\\Support;\n\nfinal class Tool { public function run(\$x) { \$x->extend('a'); } }\n"]))->toBe([]);
});
