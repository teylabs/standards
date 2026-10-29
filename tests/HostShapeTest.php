<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Mod\Layout\FileType;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Layout\Root;
use Tey\Standards\Enforcement\CheckReport;
use Tey\Standards\Enforcement\HostShape;
use Tey\Standards\Enforcement\Maker;
use Tey\Standards\Patterns\TreeCatalog;

/** A disposable copy of a fixture app, loadable as the gate's probe expects (the package's autoloader, the framework doubles, the app's own classes). */
function hostApp(string $fixture): string
{
    $base = sys_get_temp_dir().'/standards-host-'.bin2hex(random_bytes(6));
    (new Filesystem)->copyDirectory(dirname(__DIR__).'/fixtures/'.$fixture, $base);
    mkdir($base.'/vendor');
    file_put_contents($base.'/vendor/autoload.php', '<?php require '.var_export(dirname(__DIR__).'/vendor/autoload.php', true).'; require __DIR__.\'/../tests/Support/framework.php\'; spl_autoload_register(function (string $class) { $file = __DIR__."/../app/".str_replace("\\\\", "/", substr($class, 4)).".php"; if (str_starts_with($class, "App\\\\") && is_file($file)) { require_once $file; } });');
    require_once $base.'/tests/Support/framework.php';
    spl_autoload_register(function (string $class) use ($base): void {
        $file = $base.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (str_starts_with($class, 'App\\') && is_file($file)) {
            require_once $file;
        }
    });

    return $base;
}

/**
 * The reported host's layout: a custom `teylabs` layout extending `modules` (app/Modules/{module}), with a `ui` mount at app/UI for its view models and
 * Inertia pages in each module (`page`), as tey/mod declares it in the host's provider.
 */
function teylabsLayout(): object
{
    $registry = new LayoutRegistry;
    $registry->layout('teylabs')->extends('modules')
        ->mounts('ui', 'App\\UI\\', 'app/UI', fn (Root $root) => $root->generates('view-model', in: '{teylab}/ViewModels', label: 'View model'))
        ->generates('page', in: '@module/resources/js/pages', nested: true, case: 'studly', using: fn (FileType $type) => $type->extension('.vue'));

    return $registry->compile('teylabs');
}

/** @return list<string> every file under the app, relative */
function hostFiles(string $base): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
        if (! str_starts_with($path, 'vendor/')) {
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

/**
 * Each generated PHP file compiles (php -l), declares the namespace its path has in this host, and every `use App\...` names a class file that exists:
 * nothing points at a base folder the layout does not use.
 *
 * @param  list<string>  $files
 */
function expectWellPlaced(string $base, HostShape $host, array $files): void
{
    foreach ($files as $file) {
        if (! str_ends_with($file, '.php') || str_starts_with($file, 'config/') || $file === 'bootstrap/providers.php') {
            continue;
        }
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($base.'/'.$file).' 2>&1', $output, $status);
        expect($status)->toBe(0, $file.' compiles');
        $contents = (string) file_get_contents($base.'/'.$file);
        if (preg_match('/^namespace ([^;]+);$/m', $contents, $namespace) === 1) {
            expect($namespace[1])->toBe($host->namespaceOf($file), $file.' declares the namespace of where it is');
        }
        preg_match_all('/^use (App\\\\[^;\s]+);$/m', $contents, $uses);
        foreach ($uses[1] as $class) {
            $path = 'app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            expect(is_file($base.'/'.$path))->toBeTrue($file.' imports '.$class.', which exists at '.$path);
        }
    }
}

test('the reported host: workbench SlideWorkbench on kit core alone is refused, naming kit:add, and writes nothing (dry run or not)', function () {
    $base = hostApp('Modular');

    try {
        $host = new HostShape(teylabsLayout(), HostShape::kitUiAlias($base), ['core'], 'Slides');
        $before = hostFiles($base);
        foreach ([false, true] as $dryRun) {
            expect(fn () => (new Maker($base, TreeCatalog::shipped(), kit: true, host: $host, dryRun: $dryRun))->make('workbench', 'SlideWorkbench', 'page'))
                ->toThrow(InvalidArgumentException::class, "workbench needs tey/kit's workspaces and access features, which this app has not adopted. Add them first: php artisan kit:add workspaces access");
        }
        expect(hostFiles($base))->toBe($before);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('the reported host with workspaces and access adopted: the workbench goes in the Slides module, named once, importing the kit barrel, and passes standards:check', function () {
    $base = hostApp('Modular');

    try {
        $host = new HostShape(teylabsLayout(), HostShape::kitUiAlias($base), ['core', 'workspaces', 'access'], 'Slides');
        $files = (new Maker($base, TreeCatalog::shipped(), kit: true, host: $host))->make('workbench', 'SlideWorkbench', 'page');

        expect($files)->toBe([
            'app/Modules/Slides/Providers/SlideWorkbenchServiceProvider.php', 'app/Modules/Slides/Policies/SlideWorkbenchPolicy.php', 'app/Modules/Slides/routes/slides-workbench.php',
            'app/Modules/Slides/Http/Controllers/SlideWorkbenchController.php', 'app/Modules/Slides/Http/Controllers/SlideWorkbenchOrderController.php',
            'app/Modules/Slides/Http/Requests/UpdateSlideWorkbenchOrderRequest.php', 'app/Modules/Slides/Actions/ReorderSlideWorkbench.php', 'app/Modules/Slides/Queries/SlideWorkbenchItems.php',
            'app/UI/Slides/ViewModels/SlideWorkbenchViewModel.php', 'app/Modules/Slides/resources/js/pages/Workbench/Page.vue',
            'app/Modules/Slides/resources/js/pages/Workbench/Inspectors/SlideItemInspector.vue', 'tests/Feature/Modules/Slides/SlideWorkbenchTest.php', 'bootstrap/providers.php',
        ]);
        foreach ($files as $file) {
            expect($file)->not->toContain('WorkbenchWorkbench');
            expect(preg_match('#^app/(Actions|Queries|ViewModels|Policies|Http/Requests|Providers)/#', $file))->toBe(0, $file.' is not in a base folder the layout does not use');
        }
        expect(file_get_contents($base.'/app/Modules/Slides/resources/js/pages/Workbench/Page.vue'))->toContain("import { Workbench, useWorkbench } from '@/components/Kit';")->not->toContain('@kit/ui')
            ->and(file_get_contents($base.'/app/Modules/Slides/Http/Controllers/SlideWorkbenchController.php'))->toContain("Inertia::render('Slides::Workbench/Page'")->toContain('use App\\UI\\Slides\\ViewModels\\SlideWorkbenchViewModel;')
            ->and(file_get_contents($base.'/app/Modules/Slides/Providers/SlideWorkbenchServiceProvider.php'))->toContain("->routes(base_path('app/Modules/Slides/routes/slides-workbench.php'))")
            ->and(file_get_contents($base.'/bootstrap/providers.php'))->toContain('\\App\\Modules\\Slides\\Providers\\SlideWorkbenchServiceProvider::class')
            ->and(file_get_contents($base.'/tests/Feature/Modules/Slides/SlideWorkbenchTest.php'))->toContain("assertJsonPath('component', 'Slides::Workbench/Page')");
        expectWellPlaced($base, $host, $files);
        expect(CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-28'), TreeCatalog::shipped())['violations'])->toBe([]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('every recipe on the modular host with kit core alone goes in the module, compiles and passes standards:check; the kit-feature recipes are refused', function () {
    $base = hostApp('Modular');

    try {
        $host = new HostShape(teylabsLayout(), HostShape::kitUiAlias($base), ['core'], 'Slides');
        $make = fn (string $unit, string $name, string $flavor, array $options = []): array => (new Maker($base, TreeCatalog::shipped(), kit: true, host: $host))->make($unit, $name, $flavor, null, $options);
        $files = [
            ...$make('page', 'Posts', 'index'), ...$make('page', 'PostDetail', 'show'), ...$make('page', 'PostEditor', 'form'), ...$make('action', 'PublishPost', 'plain'),
            ...$make('settings-section', 'BillingSettings', 'form'), ...$make('settings-section', 'Alerts', 'toggles'), ...$make('command', 'BackfillSlugs', 'backfill'),
            ...$make('command', 'PruneDrafts', 'maintenance'), ...$make('command', 'CountSlides', 'report'), ...$make('webhook', 'Acme', 'signed'),
            ...$make('api-client', 'AcmeClient', 'http'), ...$make('api-client', 'Globex', 'sdk'), ...$make('model', 'Slide', 'domain', ['soft-deletes', 'sortable']),
        ];
        $placed = array_filter($files, fn (string $file): bool => str_starts_with($file, 'app/'));
        expect(array_filter($placed, fn (string $file): bool => ! preg_match('#^app/(Modules/Slides|UI/Slides)/#', $file)))->toBe([], 'every class goes in the Slides module (or its ui mount)')
            ->and($files)->toContain('app/Modules/Slides/Http/Controllers/Settings/BillingController.php', 'app/Modules/Slides/Console/BackfillSlugsCommand.php', 'app/Modules/Slides/Services/Acme/AcmeClient.php', 'app/Modules/Slides/Models/Slide.php')
            ->and(implode("\n", $files))->not->toContain('SettingsSettings')->not->toContain('ClientClient')
            ->and(array_filter($files, fn (string $file): bool => str_starts_with($file, 'tests/') && ! str_starts_with($file, 'tests/Feature/Modules/Slides/')))->toBe([]);
        foreach (array_filter($files, fn (string $file): bool => str_ends_with($file, '.vue')) as $page) {
            expect(file_get_contents($base.'/'.$page))->not->toContain('@kit/ui');
        }
        expect(file_get_contents($base.'/composer.json'))->not->toContain('Database\\\\Factories', 'a modular host keeps its factories in the module, under App\\');
        expectWellPlaced($base, $host, array_values(array_unique($files)));

        expect(fn () => $make('crud', 'Deck', 'resource'))->toThrow(InvalidArgumentException::class, 'php artisan kit:add workspaces access')
            ->and(fn () => $make('capability-tool', 'ReadSlides', 'read'))->toThrow(InvalidArgumentException::class, 'php artisan kit:add mcp')
            ->and(fn () => $make('model', 'Deck', 'domain', ['tenant']))->toThrow(InvalidArgumentException::class, 'php artisan kit:add workspaces');
        expect(CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-28'), TreeCatalog::shipped())['violations'])->toBe([]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('the workspace and MCP recipes on the modular host with the kit features adopted go in the module, compile and pass standards:check', function () {
    $base = hostApp('Modular');

    try {
        $host = new HostShape(teylabsLayout(), HostShape::kitUiAlias($base), ['core', 'workspaces', 'access', 'mcp'], 'Slides');
        $make = fn (string $unit, string $name, string $flavor, array $options = []): array => (new Maker($base, TreeCatalog::shipped(), kit: true, host: $host))->make($unit, $name, $flavor, null, $options);
        $files = [...$make('crud', 'Deck', 'resource', ['bulk', 'mcp', 'soft-deletes']), ...$make('capability-tool', 'ReadSlidesTool', 'read'), ...$make('model', 'Theme', 'domain', ['tenant'])];

        expect($files)->toContain('app/Modules/Slides/Models/Deck.php', 'app/Modules/Slides/Http/Controllers/DeckController.php', 'app/Modules/Slides/routes/decks.php', 'app/UI/Slides/ViewModels/DeckIndexViewModel.php', 'app/Modules/Slides/Mcp/Tools/ReadSlidesTool.php')
            ->and(implode("\n", $files))->not->toContain('ToolTool');
        expect(array_filter($files, fn (string $file): bool => str_starts_with($file, 'app/') && ! preg_match('#^app/(Modules/Slides|UI/Slides)/#', $file)))->toBe([]);
        expectWellPlaced($base, $host, array_values(array_unique($files)));
        expect(CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-28'), TreeCatalog::shipped())['violations'])->toBe([]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('a laravel host with the full kit gets exactly what it got before, file for file', function () {
    $recipes = [['page', 'Posts', 'index', []], ['page', 'PostEditor', 'form', []], ['action', 'PublishPost', 'plain', []], ['settings-section', 'Billing', 'form', []], ['command', 'BackfillSlugs', 'backfill', []],
        ['webhook', 'Acme', 'signed', []], ['api-client', 'Acme', 'http', []], ['model', 'Slide', 'domain', ['tenant']], ['capability-tool', 'ReadSlides', 'read', []], ['crud', 'Deck', 'resource', ['bulk', 'mcp']], ['workbench', 'Board', 'page', []]];
    $outputs = [];
    foreach ([null, new HostShape((new LayoutRegistry)->compile('laravel'), '@kit/ui', ['core', 'workspaces', 'access', 'settings', 'shell', 'session-ui', 'mcp'])] as $index => $host) {
        $base = hostApp('App');
        try {
            foreach ($recipes as [$unit, $name, $flavor, $options]) {
                (new Maker($base, TreeCatalog::shipped(), kit: true, host: $host))->make($unit, $name, $flavor, null, $options);
            }
            $outputs[$index] = array_map(fn (string $file): string => (string) preg_replace('/\d{4}_\d{2}_\d{2}_\d{6}/', 'TIMESTAMP', $file.' '.hash('sha256', (string) preg_replace('/\d{4}_\d{2}_\d{2}_\d{6}/', 'TIMESTAMP', (string) file_get_contents($base.'/'.$file)))), hostFiles($base));
            // Sort after the timestamps are normalised: two migrations made in the same second sort by name, and across a second boundary by time,
            // so the order of the raw names differs between runs on a slow machine.
            sort($outputs[$index]);
        } finally {
            (new Filesystem)->deleteDirectory($base);
        }
    }

    expect($outputs[1])->toBe($outputs[0]);
});

test('--dry-run lists every file a run would write, created or modified, and writes nothing', function () {
    $base = hostApp('Modular');

    try {
        $host = new HostShape(teylabsLayout(), HostShape::kitUiAlias($base), ['core', 'workspaces', 'access'], 'Slides');
        $before = hostFiles($base);
        $dry = new Maker($base, TreeCatalog::shipped(), kit: true, host: $host, dryRun: true);
        $listed = $dry->make('workbench', 'Slide', 'page');
        expect(hostFiles($base))->toBe($before);
        $plan = $dry->planned();
        expect(array_column($plan, 'path'))->toBe($listed)
            ->and(array_column($plan, 'action', 'path')['bootstrap/providers.php'])->toBe('modified')
            ->and(array_column($plan, 'action', 'path')['app/Modules/Slides/Http/Controllers/SlideWorkbenchController.php'])->toBe('created')
            ->and(array_column($plan, 'type', 'path'))->toMatchArray(['app/Modules/Slides/routes/slides-workbench.php' => 'routes', 'app/Modules/Slides/resources/js/pages/Workbench/Page.vue' => 'page', 'tests/Feature/Modules/Slides/SlideWorkbenchTest.php' => 'test', 'bootstrap/providers.php' => 'providers', 'app/Modules/Slides/Actions/ReorderSlideWorkbench.php' => 'class'])
            ->and(array_keys($plan[0]))->toBe(['path', 'type', 'action']);

        $real = new Maker($base, TreeCatalog::shipped(), kit: true, host: $host);
        expect($real->make('workbench', 'Slide', 'page'))->toBe($listed)->and($real->planned())->toBe($plan);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('a name never gets its unit suffix twice', function (string $unit, string $given, string $base) {
    expect(Maker::base($unit, $given))->toBe($base);
})->with([
    ['workbench', 'SlideWorkbench', 'Slide'], ['workbench', 'Slide', 'Slide'], ['workbench', 'Workbench', 'Workbench'], ['capability-tool', 'ReadSlidesTool', 'ReadSlides'],
    ['settings-section', 'BillingSettings', 'Billing'], ['settings-section', 'BillingController', 'Billing'], ['page', 'PostsController', 'Posts'], ['page', 'PostsPage', 'Posts'],
    ['webhook', 'AcmeWebhook', 'Acme'], ['api-client', 'AcmeClient', 'Acme'], ['crud', 'DeckController', 'Deck'], ['action', 'PublishPost', 'PublishPost'],
]);

test('the kit components are imported through @kit/ui only where the host aliases it, else the kit barrel', function () {
    $base = sys_get_temp_dir().'/standards-alias-'.bin2hex(random_bytes(6));
    mkdir($base);

    try {
        expect(HostShape::kitUiAlias($base))->toBe('@/components/Kit');
        file_put_contents($base.'/tsconfig.json', '{"compilerOptions": {"paths": {"@/*": ["./resources/js/*"]}}}');
        expect(HostShape::kitUiAlias($base))->toBe('@/components/Kit');
        file_put_contents($base.'/tsconfig.json', '{"compilerOptions": {"paths": {"@/*": ["./resources/js/*"], "@kit/ui": ["./resources/js/components/Kit/index.ts"]}}}');
        expect(HostShape::kitUiAlias($base))->toBe('@kit/ui');
        unlink($base.'/tsconfig.json');
        file_put_contents($base.'/vite.config.ts', "export default defineConfig({ resolve: { alias: { '@kit/ui': kitUi } } });");
        expect(HostShape::kitUiAlias($base))->toBe('@kit/ui');
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('a modular host needs --module, named by its layout\'s dimension; a laravel host does not', function () {
    $base = hostApp('Modular');

    try {
        $unplaced = new HostShape(teylabsLayout(), '@/components/Kit', ['core'], null);
        expect(fn () => (new Maker($base, TreeCatalog::shipped(), kit: true, host: $unplaced))->make('action', 'PublishPost', 'plain'))->toThrow(InvalidArgumentException::class, "places files by teylab: pass --module=<Name>")
            ->and((new HostShape((new LayoutRegistry)->compile('laravel')))->modular())->toBeFalse()
            ->and((new HostShape)->place('app/Actions/X.php'))->toBe(['app/Actions/X.php', null]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('workbench is refused while the host\'s own copy of the kit components predates the Workbench', function () {
    $base = hostApp('Modular');

    try {
        $host = new HostShape(teylabsLayout(), '@/components/Kit', ['core', 'workspaces', 'access'], 'Slides');
        mkdir($base.'/resources/js/components/Kit', 0777, true);
        file_put_contents($base.'/resources/js/components/Kit/index.ts', "export { default as Page } from './Layout/Page.vue';\n");
        expect(fn () => (new Maker($base, TreeCatalog::shipped(), kit: true, host: $host))->make('workbench', 'Slide', 'page'))
            ->toThrow(InvalidArgumentException::class, 'predates the Workbench. Update it first: php artisan kit:update plan --lineage=core');
        expect(is_dir($base.'/app/Modules/Slides'))->toBeFalse();
        file_put_contents($base.'/resources/js/components/Kit/index.ts', "export { default as Page } from './Layout/Page.vue';\nexport { default as Workbench } from './Workbench/Workbench.vue';\n");
        expect((new Maker($base, TreeCatalog::shipped(), kit: true, host: $host))->make('workbench', 'Slide', 'page'))->toContain('app/Modules/Slides/resources/js/pages/Workbench/Page.vue');
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});
