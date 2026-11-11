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
            'docs/mcp-capabilities.json',
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
            ...$make('command', 'BackfillSlugs', 'backfill'),
            ...$make('command', 'PruneDrafts', 'maintenance'), ...$make('command', 'CountSlides', 'report'), ...$make('webhook', 'Acme', 'signed'),
            ...$make('api-client', 'AcmeClient', 'http'), ...$make('api-client', 'Globex', 'sdk'), ...$make('model', 'Slide', 'domain', ['soft-deletes', 'sortable']),
        ];
        $placed = array_filter($files, fn (string $file): bool => str_starts_with($file, 'app/'));
        expect(array_filter($placed, fn (string $file): bool => ! preg_match('#^app/(Modules/Slides|UI/Slides)/#', $file)))->toBe([], 'every class goes in the Slides module (or its ui mount)')
            ->and($files)->toContain('app/Modules/Slides/Console/BackfillSlugsCommand.php', 'app/Modules/Slides/Services/Acme/AcmeClient.php', 'app/Modules/Slides/Models/Slide.php')
            ->and(implode("\n", $files))->not->toContain('SettingsSettings')->not->toContain('ClientClient')
            ->and(array_filter($files, fn (string $file): bool => str_starts_with($file, 'tests/') && ! str_starts_with($file, 'tests/Feature/Modules/Slides/')))->toBe([]);
        foreach (array_filter($files, fn (string $file): bool => str_ends_with($file, '.vue')) as $page) {
            expect(file_get_contents($base.'/'.$page))->not->toContain('@kit/ui');
        }
        expect(file_get_contents($base.'/composer.json'))->not->toContain('Database\\\\Factories', 'a modular host keeps its factories in the module, under App\\');
        expectWellPlaced($base, $host, array_values(array_unique($files)));

        expect(fn () => $make('crud', 'Deck', 'resource'))->toThrow(InvalidArgumentException::class, 'php artisan kit:add workspaces access')
            ->and(fn () => $make('capability-tool', 'ReadSlides', 'read'))->toThrow(InvalidArgumentException::class, 'php artisan kit:add mcp')
            ->and(fn () => $make('model', 'Deck', 'domain', ['tenant']))->toThrow(InvalidArgumentException::class, 'php artisan kit:add workspaces')
            // A module's settings section is a workspace's: it runs inside the workspace behind a policy.
            ->and(fn () => $make('settings-section', 'BillingSettings', 'form'))->toThrow(InvalidArgumentException::class, 'php artisan kit:add workspaces access');
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
        $files = [...$make('crud', 'Deck', 'resource', ['bulk', 'mcp', 'soft-deletes']), ...$make('capability-tool', 'ReadSlidesTool', 'read'), ...$make('model', 'Theme', 'domain', ['tenant']), ...$make('settings-section', 'BillingSettings', 'form')];
        // The toggles flavor, made in a module of its own.
        $toggles = (new Maker($base, TreeCatalog::shipped(), kit: true, host: new HostShape(teylabsLayout(), HostShape::kitUiAlias($base), ['core', 'workspaces', 'access'], 'Alerts')))->make('settings-section', 'Alerts', 'toggles');
        expect($files)->toContain('app/Modules/Slides/Http/Controllers/Settings/BillingController.php', 'app/Modules/Slides/routes/billing-settings.php', 'app/Modules/Slides/Providers/BillingSettingsServiceProvider.php')
            ->and($toggles)->toContain('app/Modules/Alerts/Http/Controllers/Settings/AlertsController.php')
            ->and((string) file_get_contents($base.'/app/Modules/Alerts/resources/js/pages/settings/Alerts/Page.vue'))->toContain('<Checkbox')->not->toContain('@kit/ui');
        expectWellPlaced($base, $host, array_values(array_unique($files)));

        expect($files)->toContain('app/Modules/Slides/Models/Deck.php', 'app/Modules/Slides/Http/Controllers/DeckController.php', 'app/Modules/Slides/routes/decks.php', 'app/UI/Slides/ViewModels/DeckIndexViewModel.php', 'app/Modules/Slides/Mcp/Tools/ReadSlidesTool.php')
            ->and(implode("\n", $files))->not->toContain('ToolTool');
        expect(array_filter($files, fn (string $file): bool => str_starts_with($file, 'app/') && ! preg_match('#^app/(Modules/Slides|UI/Slides)/#', $file)))->toBe([]);
        expectWellPlaced($base, $host, array_values(array_unique($files)));
        expect(CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-28'), TreeCatalog::shipped())['violations'])->toBe([]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('crud records in a module share one module capability, declared once in one module provider; --capability gives a record its own', function () {
    $base = hostApp('Modular');

    try {
        $host = new HostShape(teylabsLayout(), HostShape::kitUiAlias($base), ['core', 'workspaces', 'access', 'mcp'], 'Billing');
        $make = fn (string $name, array $options = []): array => (new Maker($base, TreeCatalog::shipped(), kit: true, host: $host))->make('crud', $name, 'resource', null, $options);
        $first = $make('Plan', ['mcp']);
        $second = $make('BillingEntity');
        $provider = 'app/Modules/Billing/Providers/BillingServiceProvider.php';

        expect($first)->toContain($provider)->not->toContain('app/Modules/Billing/Providers/PlanServiceProvider.php')
            ->and($second)->toContain($provider)->not->toContain('app/Modules/Billing/Providers/BillingEntityServiceProvider.php');
        $contents = file_get_contents($base.'/'.$provider);
        // One capability, two permissions with their aliases, declared once; each record's policy, routes and navigation added once.
        expect(substr_count($contents, '->capabilities([self::CAPABILITY])'))->toBe(1)
            ->and($contents)->toContain("public const CAPABILITY = 'billing';")
            ->toContain("->permission('billing.view', ['owner', 'admin', 'viewer'], self::CAPABILITY, ['billing.read'])")
            ->toContain("->permission('billing.manage', ['owner', 'admin'], self::CAPABILITY, ['billing.write'])")
            ->toContain('Gate::policy(Plan::class, PlanPolicy::class);')->toContain('Gate::policy(BillingEntity::class, BillingEntityPolicy::class);')
            ->toContain("->navigation('billing.read', 'Billing', 'billing');")
            ->toContain("->modulePage('billing', 'billing.read', 'Plans', 'billing/plans')")->toContain("->modulePage('billing', 'billing.read', 'Billing entities', 'billing/billing-entities')")
            ->toContain('namespace App\\Modules\\Billing\\Providers;')->toContain('use App\\Modules\\Billing\\Models\\Plan;')
            ->and(substr_count($contents, 'standards-record: plans'))->toBe(2)
            ->and(substr_count(file_get_contents($base.'/bootstrap/providers.php'), 'BillingServiceProvider::class'))->toBe(1);
        // The policies gate on the module capability: viewing is billing.view, every change billing.manage. The tools use the module scopes.
        expect(file_get_contents($base.'/app/Modules/Billing/Policies/BillingEntityPolicy.php'))->toContain("public const CAPABILITY = 'billing';")
            ->toContain("'billing.view'")->toContain("'billing.manage'")->not->toContain("'billing.create'")->not->toContain('billing-entities.')
            ->and(file_get_contents($base.'/app/Modules/Billing/Mcp/Tools/WritePlanTool.php'))->toContain("#[Capability('billing', scope: 'billing.write')]")->toContain("public const ABILITY = 'billing.write';")
            ->and(file_get_contents($base.'/app/Modules/Billing/Actions/CreatePlan.php'))->toContain("'plans.create'");

        // Making a record the module already lists again changes nothing: no file is refused, none is written.
        expect($make('Plan', ['mcp']))->toBe([])->and(file_get_contents($base.'/'.$provider))->toBe($contents);

        // A record with its own capability keeps its own provider and permissions, and the module provider is left alone.
        $own = $make('Pass', ['capability=passes']);
        expect($own)->toContain('app/Modules/Billing/Providers/PassServiceProvider.php')->not->toContain($provider)
            ->and(file_get_contents($base.'/app/Modules/Billing/Policies/PassPolicy.php'))->toContain("public const CAPABILITY = 'passes';")->toContain("'passes.create'")
            ->and(file_get_contents($base.'/'.$provider))->toBe($contents);
        expect(fn () => $make('Coupon', ['capability=Coupons_X']))->toThrow(InvalidArgumentException::class, 'must be kebab case');
        expect(CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-28'), TreeCatalog::shipped())['violations'])->toBe([]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('a module owns its URL prefix: records nest under it, the main record sits at it, settings go under /settings/<module>; outside a module nothing changes', function () {
    $base = hostApp('Modular');

    try {
        $host = new HostShape(teylabsLayout(), HostShape::kitUiAlias($base), ['core', 'workspaces', 'access', 'mcp'], 'Bookings');
        $make = fn (string $unit, string $name, string $flavor, array $options = []): array => (new Maker($base, TreeCatalog::shipped(), kit: true, host: $host))->make($unit, $name, $flavor, null, $options);
        $read = fn (string $file): string => (string) file_get_contents($base.'/'.$file);
        $make('crud', 'Resource', 'resource', ['bulk', 'soft-deletes', 'mcp']);
        $make('crud', 'Room', 'resource');
        $make('crud', 'Booking', 'resource');
        $provider = 'app/Modules/Bookings/Providers/BookingsServiceProvider.php';
        $contents = $read($provider);

        // The record rows: [record, its routes file, the prefix, the route name prefix].
        foreach ([['Resource', 'resources', 'bookings/resources', 'bookings.resources'], ['Room', 'rooms', 'bookings/rooms', 'bookings.rooms'], ['Booking', 'bookings', 'bookings', 'bookings']] as [$record, $file, $prefix, $name]) {
            $routes = $read("app/Modules/Bookings/routes/{$file}.php");
            expect($routes)->toContain("->prefix('{$prefix}')")
                ->toContain("'{$name}.index'")->toContain("'{$name}.create'")->toContain("'{$name}.show'")->toContain("['app', '{$name}.show']")
                ->and($read("app/Modules/Bookings/Http/Controllers/{$record}Controller.php"))->toContain("WorkspaceUrl::to(\$workspace, '{$prefix}/'")
                ->and((string) file_get_contents((glob($base."/app/UI/Bookings/ViewModels/{,*/}{$record}IndexViewModel.php", GLOB_BRACE) ?: [''])[0]))->toContain("WorkspaceUrl::to(\$this->workspace, '{$prefix}/create')")
                ->and((string) file_get_contents((glob($base."/tests/Feature/Modules/Bookings/{,*/}{$record}Test.php", GLOB_BRACE) ?: [''])[0]))->toContain("->slug.'/{$prefix}'.\$path");
        }
        expect($read('app/Modules/Bookings/routes/resources.php'))->toContain("->prefix('bookings/resources/selection')")->toContain("->prefix('bookings/resources/{id}/restoration')")
            ->and($read('app/Modules/Bookings/Http/Controllers/ResourceSelectionController.php'))->toContain("WorkspaceUrl::to(\$workspace, 'bookings/resources')")
            ->and($read('app/Modules/Bookings/Http/Controllers/ResourceRestorationController.php'))->toContain("WorkspaceUrl::to(\$workspace, 'bookings/resources/'");
        // One navigation link, at the module's prefix; each record is one of its pages. The main record's tab is current on its own routes, not its siblings'.
        expect(substr_count($contents, '->navigation('))->toBe(1)->and($contents)->toContain("->navigation('bookings.read', 'Bookings', 'bookings');")
            ->toContain("->modulePage('bookings', 'bookings.read', 'Resources', 'bookings/resources');")
            ->toContain("->modulePage('bookings', 'bookings.read', 'Rooms', 'bookings/rooms');")
            ->toContain("->modulePage('bookings', 'bookings.read', 'Bookings', 'bookings', active: ['route:bookings.index', 'route:short.bookings.index', 'route:bookings.create', 'route:short.bookings.create', 'route:bookings.show', 'route:short.bookings.show', 'route:bookings.edit', 'route:short.bookings.edit']);");

        // A re-run on a record already made changes nothing.
        $before = array_map(fn (string $file): string => $file.' '.hash('sha256', $read($file)), hostFiles($base));
        expect($make('crud', 'Room', 'resource'))->toBe([])->and(array_map(fn (string $file): string => $file.' '.hash('sha256', $read($file)), hostFiles($base)))->toBe($before);

        // The module's occasional setup is a workspace settings section, in the kit's settings.workspace shape. Its URL names what the person sets
        // (the section's own name), not the module: the module only decides where the code lives.
        $settings = $make('settings-section', 'BookingRules', 'form');
        expect($settings)->not->toContain('routes/web.php')
            ->and($read('app/Modules/Bookings/routes/booking-rules-settings.php'))->toContain('WorkspaceRoutes::define(')->toContain("->prefix('settings/booking-rules')")
            ->not->toContain("'settings/bookings'")
            ->toContain("WorkspaceRoutes::page(Route::get('', 'edit'), \$full, 'settings', 'workspaces.settings.booking-rules', 'Booking rules', ['session-ui', 'workspaces.settings.index']);")
            ->toContain("Route::put('', 'update');")
            ->and($read('app/Modules/Bookings/Providers/BookingRulesSettingsServiceProvider.php'))->toContain("->settingsSection('workspace', 'booking-rules', 'Booking rules',")
            ->toContain("->routes(base_path('app/Modules/Bookings/routes/booking-rules-settings.php'))")->toContain('Gate::policy(BookingRulesSettings::class, BookingRulesSettingsPolicy::class);')
            ->and($read('bootstrap/providers.php'))->toContain('BookingRulesSettingsServiceProvider')
            ->and($read('app/Modules/Bookings/Http/Controllers/Settings/BookingRulesController.php'))->toContain('app(TenantBoundary::class)->run($workspace,')
            ->toContain("authorize('viewAny', [BookingRulesSettings::class, Workspace::required()])")->toContain("Inertia::render('Bookings::settings/BookingRules/Page'")
            ->toContain("WorkspaceUrl::to(\$workspace, 'settings/booking-rules', 'settings')")->not->toContain('to_route(')
            ->and($read('app/Modules/Bookings/Policies/BookingRulesSettingsPolicy.php'))->toContain("public const CAPABILITY = 'booking-rules';")->toContain('Response::denyAsNotFound()')
            ->and($read('app/Modules/Bookings/Actions/UpdateBookingRulesSettings.php'))->toContain("authorize('update', [BookingRulesSettings::class, Workspace::required()])")->toContain('updateIfCurrent(')
            ->and((string) file_get_contents((glob($base.'/app/UI/Bookings/ViewModels/{,*/}BookingRulesSettingsViewModel.php', GLOB_BRACE) ?: [''])[0]))->toContain('extends PageViewModel')->toContain("return 'workspace';")
            ->and($read('tests/Feature/Modules/Bookings/Settings/BookingRulesTest.php'))->toContain("'/settings/booking-rules'")->toContain('$this->actingAs($this->owner);')
            ->toContain('EstablishWorkspace::run(')->toContain('standards-pattern: settings.workspace')->toContain("->assertJsonPath('component', 'Bookings::settings/BookingRules/Page')");
        // A module may hold a second, differently named section, at its own slug.
        expect($make('settings-section', 'Accounting', 'form'))->toContain('app/Modules/Bookings/routes/accounting-settings.php')
            ->and($read('app/Modules/Bookings/routes/accounting-settings.php'))->toContain("->prefix('settings/accounting')")->toContain("'workspaces.settings.accounting'");
        // A slug already taken is refused before anything is written, in this module or another.
        expect(fn () => $make('settings-section', 'BookingRulesSettings', 'form'))->toThrow(InvalidArgumentException::class, 'already at /settings/booking-rules')
            ->and(fn () => (new Maker($base, TreeCatalog::shipped(), kit: true, host: new HostShape(teylabsLayout(), HostShape::kitUiAlias($base), ['core', 'workspaces', 'access'], 'Billing')))->make('settings-section', 'Accounting', 'form'))
            ->toThrow(InvalidArgumentException::class, 'already at /settings/accounting')
            ->and(glob($base.'/app/Modules/Billing/Http/Controllers/Settings/AccountingController.php') ?: [])->toBe([]);
        // A slug the app's own routes already serve, in any routes directory, is refused too.
        (new Filesystem)->ensureDirectoryExists($base.'/somewhere/else/routes');
        file_put_contents($base.'/somewhere/else/routes/web.php', "<?php\nRoute::get('/settings/appearance', fn () => null)->name('settings.appearance.edit');\n");
        expect(fn () => $make('settings-section', 'Appearance', 'form'))->toThrow(InvalidArgumentException::class, 'already at /settings/appearance');
        // A record with its own capability in the module nests the same way, with a navigation link of its own (no module provider to sit under).
        $make('crud', 'Pass', 'resource', ['capability=passes']);
        expect($read('app/Modules/Bookings/Providers/PassServiceProvider.php'))->toContain("->navigation('passes.read', 'Passes', 'bookings/passes', active: 'bookings/passes');")
            ->and($read('app/Modules/Bookings/routes/passes.php'))->toContain("->prefix('bookings/passes')");
        expect(CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-28'), TreeCatalog::shipped())['violations'])->toBe([]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }

    // Without --module a record keeps its own prefix, route names and navigation link.
    $base = hostApp('App');
    try {
        (new Maker($base, TreeCatalog::shipped(), kit: true))->make('crud', 'Room', 'resource');
        expect(file_get_contents($base.'/routes/rooms.php'))->toContain("->prefix('rooms')")->toContain("'rooms.index'")
            ->and(file_get_contents($base.'/app/Providers/RoomServiceProvider.php'))->toContain("->navigation('rooms.read', 'Rooms', 'rooms', active: 'rooms');")->not->toContain('modulePage');
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('a module provider made before modules owned a prefix gets no module link: a new record keeps a navigation link of its own, at its nested prefix', function () {
    $base = hostApp('Modular');

    try {
        $host = new HostShape(teylabsLayout(), HostShape::kitUiAlias($base), ['core', 'workspaces', 'access'], 'Bookings');
        $make = fn (string $name): array => (new Maker($base, TreeCatalog::shipped(), kit: true, host: $host))->make('crud', $name, 'resource');
        $make('Resource');
        $provider = $base.'/app/Modules/Bookings/Providers/BookingsServiceProvider.php';
        file_put_contents($provider, (string) preg_replace("/\n\s*\/\/ standards-module: navigation[^\n]*\n\s*->navigation\('bookings.read', 'Bookings', 'bookings'\)/", '', (string) file_get_contents($provider)));
        $make('Room');
        expect(file_get_contents($provider))->toContain("->navigation('bookings.read', 'Rooms', 'bookings/rooms', active: 'bookings/rooms');")->not->toContain("modulePage('bookings', 'bookings.read', 'Rooms'");
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('a module provider the generator did not write is refused, with the lines to add by hand', function () {
    $base = hostApp('Modular');

    try {
        $host = new HostShape(teylabsLayout(), HostShape::kitUiAlias($base), ['core', 'workspaces', 'access'], 'Billing');
        @mkdir($base.'/app/Modules/Billing/Providers', 0777, true);
        file_put_contents($base.'/app/Modules/Billing/Providers/BillingServiceProvider.php', "<?php\n\nnamespace App\\Modules\\Billing\\Providers;\n\nfinal class BillingServiceProvider {}\n");

        expect(fn () => (new Maker($base, TreeCatalog::shipped(), kit: true, host: $host))->make('crud', 'Plan', 'resource'))
            ->toThrow(InvalidArgumentException::class, 'was not written by standards:make');
        // Refused before anything was written, so a retry once the markers are added is not blocked by the record's own files.
        expect(glob($base.'/app/Modules/Billing/Models/*') ?: [])->toBe([])->and(is_file($base.'/routes/plans.php'))->toBeFalse();
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
