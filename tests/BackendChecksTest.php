<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;
use Tey\Standards\Ratchet;

beforeEach(function () {
    $this->backendBase = dirname(__DIR__).'/fixtures/BackendRun'.bin2hex(random_bytes(6));
    foreach (['src/Controllers', 'src/UI', 'routes', 'bootstrap'] as $directory) {
        mkdir($this->backendBase.'/'.$directory, 0777, true);
    }
    $this->backend = function (string $rule, string $source, array $options = [], string $path = 'src/Controllers/PostsController.php'): array {
        file_put_contents($this->backendBase.'/'.$path, '<?php '.$source);
        $configuration = new Configuration(...array_replace(get_object_vars(fixtureConfiguration('Good')), [
            'base' => $this->backendBase,
            'sourceRoots' => ['src'],
            'uiRoots' => ['src/UI'],
            'controllerRoots' => ['src/Controllers'],
            'routeRoots' => ['routes'],
        ], $options));

        return (new Conformance($configuration))->violations($rule);
    };
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->backendBase);
});

test('actions bans exact namespace segments and configurable prefixes', function () {
    expect(($this->backend)('actions', 'namespace App\Operations; class SavePost {}'))->toHaveCount(1);
    expect(($this->backend)('actions', 'namespace App\OperationsArchive; class SavePost {}'))->toBe([]);
    expect(($this->backend)('actions', 'namespace App\Legacy\Posts; class SavePost {}', ['forbiddenNamespaces' => ['App\Legacy']]))->toHaveCount(1);
    expect(($this->backend)('actions', 'namespace App\Actions; class SavePost {}'))->toBe([]);
    expect(($this->backend)('actions', 'namespace App\Operations; class SavePost {}', ['forbiddenNamespaces' => []]))->toBe([]);
});

test('toast resolves facade aliases and ignores strings comments and UI internals', function (string $source) {
    expect(($this->backend)('toast', $source))->toContain(['target' => 'src/Controllers/PostsController.php', 'check' => 'flash outcomes through Toastable, not session keys']);
    unlink($this->backendBase.'/src/Controllers/PostsController.php');
    expect(($this->backend)('toast', $source, path: 'src/UI/Toast.php'))->toBe([]);
})->with([
    'with' => 'back()->with("success", "Saved");',
    'session helper' => 'session()->flash("anything", "Saved");',
    'session facade' => 'use Illuminate\Support\Facades\Session as State; State::flash("notice", "Saved");',
    'inertia alias' => 'use Inertia\Inertia as Page; Page::flash("error", "Failed");',
    'with an array' => 'back()->with(["status" => "Saved"]);',
    'with an array among other keys' => 'back()->with(["ref" => "A-1", "success" => "Saved"]);',
    'with a non-literal array key' => 'back()->with([$key => "Saved"]);',
    'with a spread' => 'back()->with([...$data]);',
    'with a spread argument' => 'back()->with(...$data);',
    'with a variable' => 'back()->with($data);',
    'inertia array' => 'use Inertia\Inertia; Inertia::flash(["error" => "Failed"]);',
    'session helper array' => 'session()->flash(["anything" => "Saved"]);',
    'session facade array' => 'use Illuminate\Support\Facades\Session; Session::flash(["notice" => "Saved"]);',
]);

test('toast reads an array flash key by key and lets literal non-outcome keys through', function (string $source) {
    expect(($this->backend)('toast', $source))->toBe([]);
})->with([
    'literal non-outcome keys' => 'back()->with(["ref" => "A-1", "count" => 2]);',
    'implicit and integer keys' => 'Post::query()->with(["author", 3 => "tags"]);',
    'inertia non-outcome key' => 'use Inertia\Inertia; Inertia::flash(["ref" => "A-1"]);',
    'a literal non-outcome key on a redirect' => 'back()->with("notice", "x");',
    'a query builder with a non-literal key and a callback' => 'Post::query()->with($key, $cb);',
    'a query builder with a variable' => 'Post::query()->with($relations);',
    'a model with a variable' => 'Post::with($relations);',
    'a query variable with a variable' => '$query->with($r);',
    'a query variable with a spread' => 'function f() { $query = Post::query(); $query->with([...$r]); }',
    'an unknown method result with a variable' => '$this->builder()->with($data);',
    'a variable reassigned off a redirect' => 'function f() { $r = back(); $r = Post::query(); $r->with($data); }',
]);

test('toast fails closed on an unreadable flash argument when the chain is rooted in a redirect or response', function (string $source) {
    expect(($this->backend)('toast', $source))->toContain(['target' => 'src/Controllers/PostsController.php', 'check' => 'flash outcomes through Toastable, not session keys']);
})->with([
    'redirect()->back()->with($data)' => 'redirect()->back()->with($data);',
    'back()->with([...$x])' => 'back()->with([...$x]);',
    'a variable assigned from redirect()->route()' => 'function f() { $r = redirect()->route("x"); return $r->with($data); }',
    'to_route()' => 'to_route("x")->with($data);',
    'Redirect facade' => 'use Illuminate\Support\Facades\Redirect; Redirect::to("/x")->with([$k => "Saved"]);',
    'response()' => 'response()->redirectTo("/x")->with($data);',
    'back()->with($key, ...)' => 'back()->with($key, "x");',
    'Inertia::flash($data)' => 'use Inertia\Inertia; Inertia::flash($data);',
    'literal outcome key on an unknown root, as before' => '$this->builder()->with(["status" => "Saved"]);',
]);

test('toast checks each literal redirect branch and accepts all toast macro forms', function (string $expression, bool $fails) {
    $source = 'namespace App; use Illuminate\Support\Facades\Redirect as Go; class PostsController { public function store() { return '.$expression.'; } }';
    expect(($this->backend)('toast', $source))->toHaveCount($fails ? 1 : 0);
})->with([
    ['back()', true], ['redirect("/posts")', true], ['to_route("posts.index")', true], ['Go::route("posts.index")', true],
    ['back()->toast("Saved")', false], ['redirect()->route("posts.index")->toastSuccess("Saved")->withInput()', false],
    ['Go::back()->toastError("Failed")', false],
    ['$ok ? back()->toast("Saved") : back()', true],
    ['match ($ok) { true => back()->toast("Saved"), default => back() }', true],
    ['response()->json([])', false],
]);

test('toast skips off-site redirects only when the target is provably another host', function (string $body, bool $fails) {
    file_put_contents($this->backendBase.'/.env.example', "APP_URL=https://app.example.test\n");
    $source = 'namespace App; use Illuminate\Support\Facades\Redirect as Go; use Inertia\Inertia; class PostsController { public function store($param) { '.$body.' } }';
    expect(($this->backend)('toast', $source))->toHaveCount($fails ? 1 : 0);
})->with([
    'Inertia::location' => ['return Inertia::location($param);', false],
    'away to an external literal' => ['return redirect()->away("https://billing.stripe.test/session");', false],
    'facade away' => ['return Go::away("https://billing.stripe.test");', false],
    'external prefix concatenation' => ['return redirect()->away("https://billing.stripe.test/s/".$param);', false],
    'external interpolation' => ['return redirect()->away("https://billing.stripe.test/s/{$param}");', false],
    'variable assigned once to an external literal' => ['$url = "https://billing.stripe.test/x"; return redirect()->away($url);', false],
    'away to a relative path' => ['return redirect()->away("/posts");', true],
    'away to the app host' => ['return redirect()->away("https://app.example.test/posts");', true],
    'away to url()' => ['return redirect()->away(url("/posts"));', true],
    'away to route()' => ['return redirect()->away(route("posts.index"));', true],
    'away to config app.url' => ['return Go::away(config("app.url"));', true],
    'away to a parameter' => ['return redirect()->away($param);', true],
    'away to a property' => ['return redirect()->away($this->url);', true],
    'away to a method result' => ['return redirect()->away($session->url());', true],
    'variable assigned twice' => ['$url = "https://billing.stripe.test"; if ($param) { $url = "/posts"; } return redirect()->away($url);', true],
    'variable passed to a call' => ['$url = "https://billing.stripe.test"; tweak($url); return redirect()->away($url);', true],
    'host not ended by the prefix' => ['return redirect()->away("https://billing".$param);', true],
    'internal route still needs it' => ['return redirect()->route("posts.index");', true],
    'internal to() still needs it' => ['return redirect()->to("https://billing.stripe.test");', true],
]);

test('toast accepts kit headless toasts flashed before the redirect, but not inside a closure or from another class', function (string $body, bool $fails) {
    $source = 'namespace App; use Tey\\Kit\\Features\\Core\\Toast; class PostsController { public function update() { '.$body.' } }';
    expect(($this->backend)('toast', $source))->toHaveCount($fails ? 1 : 0);
})->with([
    'Toastable' => ['$this->toast("Changes saved")->from("save"); return back();', false],
    'Toast facade' => ['Toast::info("Import queued"); return to_route("posts.index");', false],
    'on one branch' => ['if ($ok) { $this->toast("Saved"); } return back();', false],
    'inside a closure' => ['$f = function () { $this->toast("Saved"); }; return back();', true],
    'another Toast class' => ['\\Other\\Toast::success("Saved"); return back();', true],
    'another object' => ['$other->toast("Saved"); return back();', true],
]);

test('toast confines return checks to the controller action and literal chains', function () {
    expect(($this->backend)('toast', <<<'SOURCE'
    namespace App;
    $callable = strlen(...);
    // back()->with('success', 'comment');
    class PostsController {
        public function index() { return back(); }
        public function store() {
            $text = "session()->flash('status', 'string')";
            $callback = function () { return back(); };
            return back()->toast('Saved');
        }
    }
    SOURCE))->toBe([]);
});

test('toast checks non-GET invokables through aliased literal route registration', function (string $route, bool $fails) {
    file_put_contents($this->backendBase.'/routes/web.php', '<?php use Illuminate\Support\Facades\Route as Routes; use App\PostsController; '.$route);
    expect(($this->backend)('toast', 'namespace App; class PostsController { public function __invoke() { return back(); } }'))->toHaveCount($fails ? 1 : 0);
})->with([
    ['Routes::controller(PostsController::class)->group(function () { Routes::post("/posts", "__invoke"); });', true],
    ['Routes::get("/posts", PostsController::class);', false],
    ['Routes::post("/posts", PostsController::class);', true],
    ['Routes::middleware("auth")->delete("/posts", PostsController::class);', true],
    ['Routes::patch("/posts", [PostsController::class, "__invoke"]);', true],
    ['Routes::match(["GET", "HEAD"], "/posts", PostsController::class);', false],
    ['Routes::match(["GET", "POST"], "/posts", PostsController::class);', true],
]);

test('mail forbids aliased and inherited Mailables without instantiating them', function () {
    expect(($this->backend)('mail', 'namespace App; use Illuminate\Mail\Mailable as Letter; class Base extends Letter {} class PostMail extends Base {}'))->toHaveCount(1);
    expect(($this->backend)('mail', 'namespace App; $message = new class extends \Illuminate\Mail\Mailable {};'))->toHaveCount(1);
    expect(($this->backend)('mail', 'namespace App; use Illuminate\Support\Facades\Mail as Sender; Sender::queue("post");'))->toHaveCount(1);
    expect(($this->backend)('mail', 'namespace App; class Mail { public static function to() {} } Mail::to();'))->toBe([]);
});

test('mail composer is opt-in and resolves aliases inside toMail bodies', function () {
    $stock = 'namespace App; use Illuminate\Notifications\Messages\MailMessage as Message; class PostNotification { public function toMail($user) { return new Message; } }';
    expect(($this->backend)('mail', $stock))->toBe([]);
    expect(($this->backend)('mail', $stock, ['mailComposer' => 'App\Mail\Composer']))->toHaveCount(1);
    expect(($this->backend)('mail', 'namespace App; use App\Mail\Composer as Message; class PostNotification { public function toMail($user) { return Message::make(); } }', ['mailComposer' => 'App\Mail\Composer']))->toBe([]);
    expect(($this->backend)('mail', 'namespace App; use App\Mail\Composer; class PostNotification { public function toMail($user) { return new \Illuminate\Notifications\Messages\MailMessage; } }', ['mailComposer' => 'App\Mail\Composer']))->toHaveCount(1);
});

test('providers parses rather than executes bootstrap and rejects dev namespaces even if installed', function () {
    file_put_contents($this->backendBase.'/composer.json', json_encode(['require-dev' => ['pestphp/pest' => '*']]));
    file_put_contents($this->backendBase.'/composer.lock', json_encode(['packages-dev' => [['name' => 'pestphp/pest', 'autoload' => ['psr-4' => ['Pest\\' => 'src/']]]]]));
    $source = 'throw new \RuntimeException("must never execute"); return [\Pest\TestSuite::class, "App\\MissingProvider"];';
    $violations = ($this->backend)('providers', $source, path: 'bootstrap/providers.php');
    expect($violations)->toBe([
        ['target' => 'bootstrap/providers.php', 'check' => 'Pest\TestSuite belongs to require-dev'],
        ['target' => 'bootstrap/providers.php', 'check' => 'App\MissingProvider is not autoloadable'],
    ]);
    expect(($this->backend)('providers', 'return [\Tey\Standards\StandardsServiceProvider::class];', path: 'bootstrap/providers.php'))->toBe([]);
});

test('provider drift rejects root dev autoload namespaces and dynamic lists', function () {
    file_put_contents($this->backendBase.'/composer.json', json_encode(['autoload-dev' => ['psr-4' => ['LocalDev\\' => 'tests/']]]));
    expect(($this->backend)('providers', 'return [\LocalDev\Provider::class];', path: 'bootstrap/providers.php')[0]['check'])->toBe('LocalDev\Provider belongs to require-dev');
    expect(($this->backend)('providers', 'return providers();', path: 'bootstrap/providers.php')[0]['check'])->toBe('provider list must be a literal array');
});

test('every backend rule supports exact baselines and rejects stale entries', function (string $rule) {
    $bad = (new Conformance(fixtureConfiguration('Bad')))->violations($rule);
    $entries = array_map(fn ($finding) => ['rule' => $rule, ...$finding, 'reason' => 'Seeded backend debt', 'date' => '2026-10-24', 'expires' => '2099-12-31'], $bad);
    expect($bad)->not->toBeEmpty();
    expect((new Ratchet($entries))->failures($rule, $bad))->toBe([]);
    expect((new Ratchet($entries))->failures($rule, []))->each->toStartWith('stale exception');
})->with(['actions', 'toast', 'mail', 'providers']);

test('root runtime autoload wins over overlapping dev tool namespaces', function () {
    file_put_contents($this->backendBase.'/composer.json', json_encode(['autoload' => ['psr-4' => ['Tey\\Standards\\' => 'src/']]]));
    file_put_contents($this->backendBase.'/composer.lock', json_encode(['packages-dev' => [['name' => 'tools/example', 'autoload' => ['psr-4' => ['Tey\\Standards\\' => 'src/']]]]]));
    expect(($this->backend)('providers', 'return [\\Tey\\Standards\\StandardsServiceProvider::class];', path: 'bootstrap/providers.php'))->toBe([]);
});

test('providers classifies installed dev classmaps by their resolved package directory', function () {
    $directory = $this->backendBase.'/vendor/tools/development';
    mkdir($directory, 0777, true);
    $class = 'DevProvider'.bin2hex(random_bytes(6));
    file_put_contents($directory.'/Provider.php', '<?php class '.$class.' {}');
    file_put_contents($this->backendBase.'/composer.lock', json_encode(['packages-dev' => [['name' => 'tools/development', 'autoload' => ['classmap' => ['Provider.php']]]]]));
    $loader = new ClassLoader;
    $loader->addClassMap([$class => $directory.'/Provider.php']);
    $loader->register();
    try {
        expect(($this->backend)('providers', 'return ["'.$class.'"];', path: 'bootstrap/providers.php'))->toBe([
            ['target' => 'bootstrap/providers.php', 'check' => $class.' belongs to require-dev'],
        ]);
    } finally {
        $loader->unregister();
    }
});

test('providers does not flag entries guarded by class_exists but still flags unconditional dev providers', function () {
    file_put_contents($this->backendBase.'/composer.json', json_encode(['require-dev' => ['pestphp/pest' => '*']]));
    file_put_contents($this->backendBase.'/composer.lock', json_encode(['packages-dev' => [['name' => 'pestphp/pest', 'autoload' => ['psr-4' => ['Pest\\' => 'src/']]]]]));
    $run = fn (string $source): array => ($this->backend)('providers', $source, path: 'bootstrap/providers.php');
    $dev = ['target' => 'bootstrap/providers.php', 'check' => 'Pest\TestSuite belongs to require-dev'];

    expect($run('return [\Pest\TestSuite::class];'))->toBe([$dev]);
    expect($run('return [\Tey\Standards\StandardsServiceProvider::class, class_exists(\Pest\TestSuite::class) ? \Pest\TestSuite::class : null];'))->toBe([]);
    expect($run('return [...(class_exists(\Pest\TestSuite::class) ? [\Pest\TestSuite::class] : [])];'))->toBe([]);
    expect($run('return array_filter([\Pest\TestSuite::class, \Missing\Provider::class], class_exists(...));'))->toBe([]);
    expect($run('return array_filter([\Pest\TestSuite::class], fn ($provider) => class_exists($provider));'))->toBe([]);
    expect($run('if (class_exists(\Pest\TestSuite::class)) { return [\Pest\TestSuite::class]; } return [];'))->toBe([]);
    expect($run('if (class_exists(\Other\Thing::class)) { return [\Pest\TestSuite::class]; } return [];'))->toBe([$dev]);
    expect($run('return array_filter([\Pest\TestSuite::class], fn ($provider) => $provider !== null);'))->toBe([['target' => 'bootstrap/providers.php', 'check' => 'provider list must be a literal array']]);
    expect($run('return [class_exists(\Other\Thing::class) ? \Pest\TestSuite::class : null];'))->toBe([$dev]);
});

test('no-dev accepts a dev provider guarded by class_exists for that same class, and still flags an unguarded one', function () {
    // bootstrap/ as a discovery root, as an app that registers a dev-only provider (spatie/typescript-transformer's, say) behind class_exists.
    // The rule loads the class roots first, so they hold one autoloadable fixture class.
    copy(dirname(__DIR__).'/fixtures/Good/src/UI/Link.php', $this->backendBase.'/src/UI/Link.php');
    $run = fn (string $source): array => ($this->backend)('no-dev', $source, ['discoveryRoots' => ['bootstrap'], 'developmentNamespaces' => ['Pest']], 'bootstrap/providers.php');
    $dev = ['target' => 'bootstrap/providers.php', 'check' => 'depends on Pest\TestSuite'];

    expect($run('return [\Tey\Standards\StandardsServiceProvider::class, ...(class_exists(\Pest\TestSuite::class) ? [\Pest\TestSuite::class] : [])];'))->toBe([])
        ->and($run('use Pest\TestSuite; return [...(class_exists(TestSuite::class) ? [TestSuite::class] : [])];'))->toBe([])
        ->and($run('return [class_exists(\Pest\TestSuite::class) ? \Pest\TestSuite::class : null];'))->toBe([])
        ->and($run('return array_filter([\Pest\TestSuite::class], class_exists(...));'))->toBe([])
        ->and($run('if (class_exists(\Pest\TestSuite::class)) { return [\Pest\TestSuite::class]; } return [];'))->toBe([])
        ->and($run('return [\Pest\TestSuite::class];'))->toBe([$dev])
        ->and($run('use Pest\TestSuite; return [TestSuite::class, ...(class_exists(TestSuite::class) ? [TestSuite::class] : [])];'))->toBe([$dev])
        ->and($run('return [...(class_exists(\Other\Thing::class) ? [\Pest\TestSuite::class] : [])];'))->toBe([$dev])
        ->and($run('return [...(class_exists(\Pest\TestSuite::class) ? [] : [\Pest\TestSuite::class])];'))->toBe([$dev])
        ->and($run('return array_filter([\Pest\TestSuite::class], fn ($provider) => $provider !== null);'))->toBe([$dev]);
});

test('no-dev accepts an app provider registered only behind class_exists of the dev class it extends, and fails closed on any other registration', function () {
    // An app provider extending a dev package's provider (spatie/typescript-transformer's, say), registered only when that parent exists.
    // The rule loads the class roots first, so they hold one autoloadable fixture class.
    copy(dirname(__DIR__).'/fixtures/Good/src/UI/Link.php', $this->backendBase.'/src/UI/Link.php');
    mkdir($this->backendBase.'/app/Support', 0777, true);
    mkdir($this->backendBase.'/config', 0777, true);
    file_put_contents($this->backendBase.'/app/Support/TypeScriptProvider.php', '<?php namespace App\Support; use Pest\TestSuite; use Pest\Plugin; final class TypeScriptProvider extends TestSuite { public function transformers(): array { return [Plugin::class]; } }');
    $run = function (string $providers, string $config = 'return [];'): array {
        file_put_contents($this->backendBase.'/config/app.php', '<?php '.$config);

        return array_column(($this->backend)('no-dev', $providers, ['discoveryRoots' => ['app', 'bootstrap'], 'developmentNamespaces' => ['Pest']], 'bootstrap/providers.php'), 'target');
    };
    $parentGuard = 'use App\Support\TypeScriptProvider; return [...(class_exists(\Pest\TestSuite::class) ? [TypeScriptProvider::class] : [])];';

    expect($run($parentGuard))->toBe([])
        ->and($run('if (class_exists(\Pest\TestSuite::class)) { return [\App\Support\TypeScriptProvider::class]; } return [];'))->toBe([])
        // The same provider also registered unguarded, guarded by an unrelated or a non-dev class, or in the else branch: its dev references are reported.
        ->and($run('return [\App\Support\TypeScriptProvider::class, ...(class_exists(\Pest\TestSuite::class) ? [\App\Support\TypeScriptProvider::class] : [])];'))->toContain('app/Support/TypeScriptProvider.php')
        ->and($run('return [...(class_exists(\Pest\Unrelated::class) ? [\App\Support\TypeScriptProvider::class] : [])];'))->toContain('app/Support/TypeScriptProvider.php')
        ->and($run('return [...(class_exists(\Other\Thing::class) ? [\App\Support\TypeScriptProvider::class] : [])];'))->toContain('app/Support/TypeScriptProvider.php')
        ->and($run('return [...(class_exists(\Pest\TestSuite::class) ? [] : [\App\Support\TypeScriptProvider::class])];'))->toContain('app/Support/TypeScriptProvider.php')
        // Registered anywhere else as well (config/app.php providers, by name or as a string): reported.
        ->and($run($parentGuard, "return ['providers' => [App\\Support\\TypeScriptProvider::class]];"))->toContain('app/Support/TypeScriptProvider.php')
        ->and($run($parentGuard, "return ['providers' => ['App\\\\Support\\\\TypeScriptProvider']];"))->toContain('app/Support/TypeScriptProvider.php')
        // Never registered at all: nothing gates it.
        ->and($run('return [];'))->toContain('app/Support/TypeScriptProvider.php');
});

test('no-dev ignores Laravel\'s generated bootstrap/cache manifests, but a real bootstrap/app.php reference still fails closed', function () {
    copy(dirname(__DIR__).'/fixtures/Good/src/UI/Link.php', $this->backendBase.'/src/UI/Link.php');
    mkdir($this->backendBase.'/app/Support', 0777, true);
    mkdir($this->backendBase.'/bootstrap/cache', 0777, true);
    file_put_contents($this->backendBase.'/app/Support/TypeScriptProvider.php', '<?php namespace App\Support; use Pest\TestSuite; final class TypeScriptProvider extends TestSuite {}');
    // What Laravel writes on boot: the manifest names the provider, as a string and by class.
    file_put_contents($this->backendBase.'/bootstrap/cache/services.php', "<?php return ['providers' => ['App\\\\Support\\\\TypeScriptProvider', \\Pest\\TestSuite::class], 'eager' => [App\\Support\\TypeScriptProvider::class]];");
    $run = fn (): array => array_column(($this->backend)('no-dev', 'return [...(class_exists(\Pest\TestSuite::class) ? [\App\Support\TypeScriptProvider::class] : [])];',
        ['discoveryRoots' => ['app', 'bootstrap'], 'developmentNamespaces' => ['Pest']], 'bootstrap/providers.php'), 'target');

    expect($run())->toBe([]);

    file_put_contents($this->backendBase.'/bootstrap/app.php', '<?php return Illuminate\Foundation\Application::configure()->withProviders([App\Support\TypeScriptProvider::class])->create();');
    expect($run())->toContain('app/Support/TypeScriptProvider.php');
});

test('toast accepts a guest form whose back() returns to a page rendering kit FormStatus, and nothing less provable', function (string $routes, string $page, string $show, string $store, bool $fails) {
    file_put_contents($this->backendBase.'/routes/web.php', '<?php use Illuminate\Support\Facades\Route; use App\ContactController; '.$routes);
    file_put_contents($this->backendBase.'/bootstrap/app.php', guestBootstrap('$middleware->web(append: [\App\Http\Middleware\HandleInertiaRequests::class]);'));
    mkdir($this->backendBase.'/resources/js/pages/Public', 0777, true);
    file_put_contents($this->backendBase.'/resources/js/pages/Public/Contact.vue', $page);
    $controller = 'namespace App; use Inertia\Inertia; class ContactController { public function show($name) { '.$show.' } public function store() { '.$store.' } }';
    expect(($this->backend)('toast', $controller, ['pageRoots' => ['resources/js/pages']], 'src/Controllers/ContactController.php'))->toHaveCount($fails ? 1 : 0);
})->with(function () {
    $guest = 'Route::get("/contact", [ContactController::class, "show"]); Route::post("/contact", [ContactController::class, "store"])->middleware("throttle:6,1");';
    $status = "<script setup lang=\"ts\">\nimport { FormStatus, Button } from '@/components/Kit';\n</script>\n<template>\n    <FormStatus />\n</template>\n";
    $render = 'return Inertia::render("Public/Contact");';

    return [
        'guest page with FormStatus' => [$guest, $status, $render, 'return back();', false],
        'guest page importing from Kit/Form, inertia() helper, redirect()->back()' => [$guest, str_replace("'@/components/Kit'", "'@/components/Kit/Form'", $status), 'return inertia("Public/Contact");', 'return redirect()->back()->withInput();', false],
        'guest group with prefix and guest middleware' => ['Route::middleware(["guest"])->prefix("site")->group(function () { Route::get("contact", [ContactController::class, "show"]); Route::post("contact", [ContactController::class, "store"]); });', $status, $render, 'return back();', false],
        'guest page without FormStatus' => [$guest, "<template>\n    <form />\n</template>\n", $render, 'return back();', true],
        'FormStatus rendered but not imported from kit' => [$guest, str_replace("'@/components/Kit'", "'@/components/FormStatus'", $status), $render, 'return back();', true],
        'FormStatus imported but not rendered' => [$guest, str_replace('<FormStatus />', '<div />', $status), $render, 'return back();', true],
        'signed-in route with FormStatus' => ['Route::middleware("auth")->group(function () { Route::get("/contact", [ContactController::class, "show"]); Route::post("/contact", [ContactController::class, "store"]); });', $status, $render, 'return back();', true],
        'auth middleware after the verb' => ['Route::get("/contact", [ContactController::class, "show"]); Route::post("/contact", [ContactController::class, "store"])->middleware(["throttle:6,1", "auth"]);', $status, $render, 'return back();', true],
        'non-literal middleware' => ['Route::get("/contact", [ContactController::class, "show"]); Route::post("/contact", [ContactController::class, "store"])->middleware($stack);', $status, $render, 'return back();', true],
        'unresolvable page name' => [$guest, $status, 'return Inertia::render($name);', 'return back();', true],
        'two pages from one action' => [$guest, $status, 'return $name ? Inertia::render("Public/Contact") : Inertia::render("Public/Other");', 'return back();', true],
        'missing page file' => [$guest, $status, 'return Inertia::render("Public/Missing");', 'return back();', true],
        'no GET route for the URI' => ['Route::get("/contact-us", [ContactController::class, "show"]); Route::post("/contact", [ContactController::class, "store"]);', $status, $render, 'return back();', true],
        'no route at all' => ['', $status, $render, 'return back();', true],
        'array-form group' => ['Route::group(["middleware" => "web"], function () { Route::get("/contact", [ContactController::class, "show"]); Route::post("/contact", [ContactController::class, "store"]); });', $status, $render, 'return back();', true],
        'also registered by a resource' => [$guest.' Route::resource("contacts", ContactController::class);', $status, $render, 'return back();', true],
        'route file included inside a group' => [$guest.' Route::middleware("auth")->group(function () { require __DIR__."/account.php"; });', $status, $render, 'return back();', true],
        'redirect to a route instead of back' => [$guest, $status, $render, 'return to_route("home");', true],
    ];
});

test('the FormStatus exemption follows the page\'s .vue imports three levels deep, through kit-owned files, and stops at a cycle', function (array $components, bool $fails) {
    file_put_contents($this->backendBase.'/routes/web.php', '<?php use Illuminate\Support\Facades\Route; use App\ContactController; Route::get("/contact", [ContactController::class, "show"]); Route::post("/contact", [ContactController::class, "store"]);');
    file_put_contents($this->backendBase.'/bootstrap/app.php', guestBootstrap(''));
    foreach ($components as $path => $source) {
        @mkdir(dirname($this->backendBase.'/'.$path), 0777, true);
        file_put_contents($this->backendBase.'/'.$path, $source);
    }
    $controller = 'namespace App; use Inertia\Inertia; class ContactController { public function show() { return Inertia::render("Public/Contact"); } public function store() { return back(); } }';
    expect(($this->backend)('toast', $controller, ['pageRoots' => ['resources/js/pages']], 'src/Controllers/ContactController.php'))->toHaveCount($fails ? 1 : 0);
})->with(function () {
    $page = 'resources/js/pages/Public/Contact.vue';
    $uses = fn (string ...$specifiers): string => "<script setup lang=\"ts\">\n".implode('', array_map(fn (string $specifier): string => 'import Part'.md5($specifier)." from '{$specifier}';\n", $specifiers))."</script>\n<template>\n    <div />\n</template>\n";
    $status = "<script setup lang=\"ts\">\nimport { FormStatus } from '@/components/Kit/Form';\n</script>\n<template>\n    <FormStatus />\n</template>\n";

    return [
        'FormStatus at depth 2' => [[$page => $uses('@/components/ContactForm.vue'), 'resources/js/components/ContactForm.vue' => $uses('./ContactFooter.vue'), 'resources/js/components/ContactFooter.vue' => $status], false],
        'FormStatus at depth 3, relative import without extension' => [[$page => $uses('../../components/A'), 'resources/js/components/A.vue' => $uses('./B'), 'resources/js/components/B.vue' => $uses('./C'), 'resources/js/components/C.vue' => $status], false],
        'a kit-owned file renders FormStatus' => [[$page => $uses('@/components/Kit/Auth/AuthCard.vue'), 'resources/js/components/Kit/Auth/AuthCard.vue' => $status], false],
        'FormStatus at depth 4' => [[$page => $uses('@/components/A.vue'), 'resources/js/components/A.vue' => $uses('./B.vue'), 'resources/js/components/B.vue' => $uses('./C.vue'), 'resources/js/components/C.vue' => $uses('./D.vue'), 'resources/js/components/D.vue' => $status], true],
        'an import cycle without FormStatus' => [[$page => $uses('@/components/A.vue'), 'resources/js/components/A.vue' => $uses('./B.vue', '@/pages/Public/Contact.vue'), 'resources/js/components/B.vue' => $uses('./A.vue')], true],
        'a cycle on the way to FormStatus' => [[$page => $uses('@/components/A.vue'), 'resources/js/components/A.vue' => $uses('./A.vue', './B.vue'), 'resources/js/components/B.vue' => $status], false],
        'imported through an alias this reader does not follow' => [[$page => $uses('~/components/ContactForm.vue'), 'resources/js/components/ContactForm.vue' => $status], true],
        'the child renders FormStatus from elsewhere' => [[$page => $uses('@/components/ContactForm.vue'), 'resources/js/components/ContactForm.vue' => str_replace('@/components/Kit/Form', '@/components/Status', $status)], true],
    ];
});

test('a guest back()->with(<key>) passes both toast checks only when a FormStatus in the page tree binds :message to that key', function (string $store, string $page, array $components, string $routes, array $checks) {
    file_put_contents($this->backendBase.'/routes/web.php', '<?php use Illuminate\Support\Facades\Route; use App\ContactController; '.$routes);
    file_put_contents($this->backendBase.'/bootstrap/app.php', guestBootstrap(''));
    foreach (['resources/js/pages/Public/Contact.vue' => $page, ...$components] as $path => $source) {
        @mkdir(dirname($this->backendBase.'/'.$path), 0777, true);
        file_put_contents($this->backendBase.'/'.$path, $source);
    }
    $controller = 'namespace App; use Inertia\Inertia; class ContactController { public function show() { return Inertia::render("Public/Contact"); } public function store($key) { '.$store.' } }';
    $found = array_column(($this->backend)('toast', $controller, ['pageRoots' => ['resources/js/pages']], 'src/Controllers/ContactController.php'), 'check');
    expect($found)->toBe($checks);
})->with(function () {
    $guest = 'Route::get("/contact", [ContactController::class, "show"]); Route::post("/contact", [ContactController::class, "store"]);';
    $view = fn (string $message, string $script = ''): string => "<script setup lang=\"ts\">\nimport { FormStatus } from '@/components/Kit';\n{$script}</script>\n<template>\n    <FormStatus {$message}/>\n</template>\n";
    $toastable = 'flash outcomes through Toastable, not session keys';
    $redirect = 'ContactController::store redirect must chain ->toast*()';
    $child = "<script setup lang=\"ts\">\nimport ContactForm from '@/components/ContactForm.vue';\n</script>\n<template>\n    <ContactForm />\n</template>\n";

    return [
        'named key read through $page.props.flash' => ['return back()->with("notice", "Thanks");', $view(':message="$page.props.flash.notice" '), [], $guest, []],
        'named key read through usePage().props.flash?.' => ['return back()->withInput()->with("notice", "Thanks");', $view(':message="usePage().props.flash?.notice ?? null" '), [], $guest, []],
        'named key read by a child component' => ['return back()->with(["notice" => "Thanks"]);', $child, ['resources/js/components/ContactForm.vue' => $view('v-bind:message="flash.notice" ')], $guest, []],
        'named key as a declared page prop' => ['return back()->with("notice", "Thanks");', $view(':message="props.notice" ', "const props = defineProps<{ notice?: string }>();\n"), [], $guest, []],
        'status read through flash: both checks pass' => ['if ($key) { return back()->with("status", "We emailed you a link."); } return back();', $view(':message="flash.status" '), [], $guest, []],
        'named key not referenced' => ['return back()->with("notice", "Thanks");', $view(':message="$page.props.flash.other" '), [], $guest, [$redirect]],
        'named key with a bare FormStatus' => ['return back()->with("notice", "Thanks");', $view(''), [], $guest, [$redirect]],
        'one of two keys not referenced' => ['return back()->with(["notice" => "Thanks", "ref" => "A-1"]);', $view(':message="flash.notice" '), [], $guest, [$redirect]],
        'a key prefixing the referenced one' => ['return back()->with("note", "Thanks");', $view(':message="flash.notes" '), [], $guest, [$redirect]],
        'a page prop named like the key on a child, not the page' => ['return back()->with("notice", "Thanks");', $child, ['resources/js/components/ContactForm.vue' => $view(':message="notice" ', "defineProps<{ notice?: string }>();\n")], $guest, [$redirect]],
        'non-literal key' => ['return back()->with($key, "Thanks");', $view(':message="flash.notice" '), [], $guest, [$toastable, $redirect]],
        'status not referenced: both checks fail' => ['return back()->with("status", "Sent");', $view(':message="flash.notice" '), [], $guest, [$toastable, $redirect]],
        'status on a signed-in route: both checks fail' => ['return back()->with("status", "Sent");', $view(':message="flash.status" '), [], 'Route::middleware("auth")->group(function () { '.$guest.' });', [$toastable, $redirect]],
        'status in an array flash, not referenced' => ['return back()->with(["status" => "Sent", "ref" => "A-1"]);', $view(':message="flash.notice" '), [], $guest, [$toastable, $redirect]],
        'status in an array flash read through flash' => ['return back()->with(["status" => "We emailed you a link."]);', $view(':message="flash.status" '), [], $guest, []],
        'status and an unread key in one array flash' => ['return back()->with(["status" => "Sent", "ref" => "A-1"]);', $view(':message="flash.status" '), [], $guest, [$toastable, $redirect]],
        'a variable array flash' => ['$data = ["status" => "Sent"]; return back()->with($data);', $view(':message="flash.status" '), [], $guest, [$toastable, $redirect]],
        'a spread array flash' => ['$data = ["status" => "Sent"]; return back()->with([...$data]);', $view(':message="flash.status" '), [], $guest, [$toastable, $redirect]],
        'status flashed inside a closure' => ['return tap(back(), fn () => back()->with("status", "Sent"));', $view(':message="flash.status" '), [], $guest, [$toastable]],
    ];
});

function guestBootstrap(string $middleware): string
{
    return '<?php use Illuminate\Foundation\Application; use Illuminate\Foundation\Configuration\Middleware; return Application::configure(basePath: dirname(__DIR__))->withRouting(web: __DIR__."/../routes/web.php")->withMiddleware(function (Middleware $middleware): void { '.$middleware.' })->create();';
}

test('a guest form may redirect to a literal named guest GET route whose page reads every flashed key through FormStatus', function (string $store, string $moduleRoutes, string $webRoutes, string $partial, array $checks) {
    file_put_contents($this->backendBase.'/bootstrap/app.php', guestBootstrap('$middleware->web(append: [\App\Http\Middleware\HandleInertiaRequests::class]);'));
    $files = [
        'src/Modules/Signup/routes/web.php' => '<?php use Illuminate\Support\Facades\Route; use App\Modules\Signup\JoinController; '.$moduleRoutes,
        'routes/web.php' => '<?php use Illuminate\Support\Facades\Route; use App\HomeController; '.$webRoutes,
        'src/Controllers/HomeController.php' => '<?php namespace App; use Inertia\Inertia; class HomeController { public function __invoke() { return Inertia::render("Welcome/Page"); } }',
        'resources/js/pages/Welcome/Page.vue' => "<script setup lang=\"ts\">\nimport JoinForm from './Partials/JoinForm.vue';\n</script>\n<template>\n    <JoinForm />\n</template>\n",
        'resources/js/pages/Welcome/Partials/JoinForm.vue' => $partial,
    ];
    mkdir($this->backendBase.'/src/Modules/Signup/Controllers', 0777, true);
    foreach ($files as $path => $source) {
        is_dir(dirname($this->backendBase.'/'.$path)) || mkdir(dirname($this->backendBase.'/'.$path), 0777, true);
        file_put_contents($this->backendBase.'/'.$path, $source);
    }
    $controller = 'namespace App\Modules\Signup; use Inertia\Inertia; class JoinController { public function __invoke($key) { '.$store.' } }';
    $found = array_column(($this->backend)('toast', $controller, ['pageRoots' => ['resources/js/pages'], 'routeRoots' => ['routes', 'src/Modules/*/routes'], 'controllerRoots' => ['src/Controllers', 'src/Modules/*/Controllers']], 'src/Modules/Signup/Controllers/JoinController.php'), 'check');
    expect($found)->toBe($checks);
})->with(function () {
    $post = 'Route::post("/join", JoinController::class)->middleware(["web", "throttle:join"])->name("join.store");';
    $home = 'Route::get("/", HomeController::class)->name("home");';
    $partial = fn (string $message): string => "<script setup lang=\"ts\">\nimport { usePage } from '@inertiajs/vue3';\nimport { FormStatus } from '@/components/Kit';\nconst page = usePage();\n</script>\n<template>\n    <FormStatus v-if=\"page.flash?.joined\" :message=\"{$message}\" />\n</template>\n";
    $reads = $partial('page.flash?.joined as string');
    $flash = 'Inertia::flash("joined", "Thanks"); ';
    $redirect = 'JoinController::__invoke redirect must chain ->toast*()';

    return [
        'to_route to a guest GET whose partial reads the key' => [$flash.'return to_route("home");', $post, $home, $reads, []],
        'redirect()->route() with a group name prefix' => [$flash.'return redirect()->route("site.home");', $post, 'Route::name("site.")->middleware("web")->group(function () { Route::get("/", HomeController::class)->name("home"); });', $reads, []],
        'Redirect::route() with a with() key' => ['return \Illuminate\Support\Facades\Redirect::route("home")->with("joined", "Thanks");', $post, $home, $reads, []],
        'an unknown name' => [$flash.'return to_route("welcome");', $post, $home, $reads, [$redirect]],
        'two routes with the name' => [$flash.'return to_route("home");', $post, $home.' Route::get("/start", HomeController::class)->name("home");', $reads, [$redirect]],
        'a non-literal route name elsewhere' => [$flash.'return to_route("home");', $post, $home.' Route::get("/start", HomeController::class)->name($key);', $reads, [$redirect]],
        'a signed-in target' => [$flash.'return to_route("home");', $post, 'Route::middleware("auth")->group(function () { Route::get("/", HomeController::class)->name("home"); });', $reads, [$redirect]],
        'a non-literal name' => [$flash.'return to_route($key);', $post, $home, $reads, [$redirect]],
        'with parameters' => [$flash.'return to_route("home", ["ref" => 1]);', $post, $home, $reads, [$redirect]],
        'a target with a URI parameter' => [$flash.'return to_route("home");', $post, 'Route::get("/{locale}", HomeController::class)->name("home");', $reads, [$redirect]],
        'a POST target' => [$flash.'return to_route("join.store");', $post, $home, $reads, [$redirect]],
        'the key unread' => [$flash.'return to_route("home");', $post, $home, $partial('page.flash?.other as string'), [$redirect]],
        'no FormStatus' => [$flash.'return to_route("home");', $post, $home, "<template>\n    <form />\n</template>\n", [$redirect]],
        'a signed-in POST route' => [$flash.'return to_route("home");', 'Route::post("/join", JoinController::class)->middleware(["web", "auth"]);', $home, $reads, [$redirect]],
    ];
});

test('declared guest-safe middleware keeps a FormStatus route guest, and auth middleware can never be declared', function (array $declared, string $middleware, array $checks) {
    $classes = [
        'src/Middleware/EnterWorkspace.php' => '<?php namespace App\Http\Middleware; class EnterWorkspace { public function handle($request, $next) { return $next($request); } }',
        'src/Middleware/RequireFeature.php' => '<?php namespace App\Http\Middleware; class RequireFeature { public function handle($request, $next, string $feature) { return $next($request); } }',
        'src/Middleware/Members.php' => '<?php namespace App\Http\Middleware; use Illuminate\Auth\Middleware\Authenticate; class Members extends Authenticate {}',
        'src/Middleware/Wraps.php' => '<?php namespace App\Http\Middleware; class Wraps { public function handle($request, $next) { return app(\Illuminate\Auth\Middleware\Authenticate::class)->handle($request, $next); } }',
        'src/Middleware/HandleInertiaRequests.php' => '<?php namespace App\Http\Middleware; class HandleInertiaRequests {}',
    ];
    mkdir($this->backendBase.'/src/Middleware', 0777, true);
    foreach ($classes as $path => $source) {
        file_put_contents($this->backendBase.'/'.$path, $source);
    }
    file_put_contents($this->backendBase.'/bootstrap/app.php', guestBootstrap('$middleware->alias(["feature" => \App\Http\Middleware\RequireFeature::class, "members" => \App\Http\Middleware\Members::class]); $middleware->web(append: [\App\Http\Middleware\HandleInertiaRequests::class, \App\Http\Middleware\EnterWorkspace::class]);'));
    file_put_contents($this->backendBase.'/routes/web.php', '<?php use Illuminate\Support\Facades\Route; use App\ContactController; Route::get("/contact", [ContactController::class, "show"]); Route::post("/contact", [ContactController::class, "store"])->middleware(["web", '.$middleware.']);');
    mkdir($this->backendBase.'/resources/js/pages/Public', 0777, true);
    file_put_contents($this->backendBase.'/resources/js/pages/Public/Contact.vue', "<script setup lang=\"ts\">\nimport { FormStatus } from '@/components/Kit';\n</script>\n<template>\n    <FormStatus :message=\"\$page.flash.joined\" />\n</template>\n");
    $controller = 'namespace App; use Inertia\Inertia; class ContactController { public function show() { return Inertia::render("Public/Contact"); } public function store() { Inertia::flash("joined", "Thanks"); return back(); } }';
    $found = array_column(($this->backend)('toast', $controller, ['pageRoots' => ['resources/js/pages'], 'guestSafeMiddleware' => $declared], 'src/Controllers/ContactController.php'), 'check');
    expect($found)->toBe($checks);
})->with(function () {
    $workspace = ['middleware' => 'App\Http\Middleware\EnterWorkspace', 'reason' => 'resolves the route\'s workspace; signs no one in'];
    $feature = ['middleware' => 'feature:*', 'reason' => 'a feature flag gate; 404s when off, signs no one in'];
    $redirect = 'ContactController::store redirect must chain ->toast*()';

    return [
        'a declared app class in the web group and a declared feature: gate' => [[$workspace, $feature], '"feature:signup"', []],
        'an exact alias with parameters' => [[$workspace, ['middleware' => 'feature:signup', 'reason' => 'a feature flag gate']], '"feature:signup"', []],
        'an exact alias does not match other parameters' => [[$workspace, ['middleware' => 'feature:signup', 'reason' => 'a feature flag gate']], '"feature:other"', [$redirect]],
        'a declared class on the route' => [[$workspace], '\App\Http\Middleware\EnterWorkspace::class', []],
        'undeclared app middleware in the web group still fails' => [[$feature], '"feature:signup"', [$redirect]],
        'an undeclared feature: gate still fails' => [[$workspace], '"feature:signup"', [$redirect]],
        'a missing reason' => [[$workspace, ['middleware' => 'feature:*']], '"feature:signup"', ['guestSafeMiddleware entry 1 (feature:*) needs a non-empty reason', $redirect]],
        'a blank reason' => [[$workspace, ['middleware' => 'feature:*', 'reason' => '  ']], '"feature:signup"', ['guestSafeMiddleware entry 1 (feature:*) needs a non-empty reason', $redirect]],
        'a non-string middleware' => [[$workspace, ['middleware' => ['feature'], 'reason' => 'x']], '"feature:signup"', ['guestSafeMiddleware entry 1 needs a non-empty string middleware', $redirect]],
        'a declared subclass of Authenticate' => [[$workspace, ['middleware' => 'App\Http\Middleware\Members', 'reason' => 'x']], '\App\Http\Middleware\Members::class', ['guestSafeMiddleware entry 1 (App\Http\Middleware\Members) extends Illuminate\Auth\Middleware\Authenticate', $redirect]],
        'a declared class naming Authenticate' => [[$workspace, ['middleware' => 'App\Http\Middleware\Wraps', 'reason' => 'x']], '\App\Http\Middleware\Wraps::class', ['guestSafeMiddleware entry 1 (App\Http\Middleware\Wraps) names Illuminate\Auth\Middleware\Authenticate in App\Http\Middleware\Wraps', $redirect]],
        'a declared class that cannot be read' => [[$workspace, ['middleware' => 'App\Http\Middleware\Missing', 'reason' => 'x']], '\App\Http\Middleware\Missing::class', ['guestSafeMiddleware entry 1 (App\Http\Middleware\Missing) cannot be read (App\Http\Middleware\Missing is not found in the source roots or composer\'s autoload maps)', $redirect]],
        'a declared auth:web' => [[$workspace, ['middleware' => 'auth:web', 'reason' => 'x']], '"auth:web"', ['guestSafeMiddleware entry 1 (auth:web) names an auth middleware', $redirect]],
        'a declared can:*' => [[$workspace, ['middleware' => 'can:*', 'reason' => 'x']], '"can:view,post"', ['guestSafeMiddleware entry 1 (can:*) names an auth middleware', $redirect]],
        'a declared alias bootstrap maps to an Authenticate subclass' => [[$workspace, ['middleware' => 'members', 'reason' => 'x']], '"members"', ['guestSafeMiddleware entry 1 (members) is an alias of App\Http\Middleware\Members, which extends Illuminate\Auth\Middleware\Authenticate', $redirect]],
        'a declared Authenticate itself' => [[$workspace, ['middleware' => 'Illuminate\Auth\Middleware\Authenticate', 'reason' => 'x']], '"web"', ['guestSafeMiddleware entry 1 (Illuminate\Auth\Middleware\Authenticate) is auth middleware']],
    ];
});

test('the FormStatus exemption fails closed unless bootstrap/app.php and the app providers provably keep the route guest', function (?string $bootstrap, ?string $provider, bool $fails) {
    file_put_contents($this->backendBase.'/routes/web.php', '<?php use Illuminate\Support\Facades\Route; use App\ContactController; Route::get("/contact", [ContactController::class, "show"]); Route::post("/contact", [ContactController::class, "store"]);');
    if ($bootstrap !== null) {
        file_put_contents($this->backendBase.'/bootstrap/app.php', $bootstrap);
    }
    if ($provider !== null) {
        mkdir($this->backendBase.'/app/Providers', 0777, true);
        file_put_contents($this->backendBase.'/app/Providers/RouteServiceProvider.php', '<?php namespace App\Providers; use Illuminate\Support\Facades\Route; class RouteServiceProvider { public function boot() { '.$provider.' } }');
    }
    mkdir($this->backendBase.'/resources/js/pages/Public', 0777, true);
    file_put_contents($this->backendBase.'/resources/js/pages/Public/Contact.vue', "<script setup lang=\"ts\">\nimport { FormStatus } from '@/components/Kit';\n</script>\n<template>\n    <FormStatus />\n</template>\n");
    $controller = 'namespace App; use Inertia\Inertia; class ContactController { public function show() { return Inertia::render("Public/Contact"); } public function store() { return back(); } }';
    expect(($this->backend)('toast', $controller, ['pageRoots' => ['resources/js/pages']], 'src/Controllers/ContactController.php'))->toHaveCount($fails ? 1 : 0);
})->with([
    'web appends only HandleInertiaRequests and PrepareKitRequest' => [guestBootstrap('$middleware->web(append: [\App\Http\Middleware\HandleInertiaRequests::class, \Tey\Kit\Features\SessionUi\Http\Middleware\PrepareKitRequest::class]); $middleware->redirectGuestsTo("/login");'), null, false],
    'preload headers appended globally, a harmless provider' => [guestBootstrap('$middleware->append(\Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class);'), 'Route::pattern("id", "[0-9]+");', false],
    'auth appended to web' => [guestBootstrap('$middleware->web(append: [\App\Http\Middleware\HandleInertiaRequests::class, \Illuminate\Auth\Middleware\Authenticate::class]);'), null, true],
    'auth appended to the web group by name' => [guestBootstrap('$middleware->appendToGroup("web", "auth");'), null, true],
    'global prepend of an unknown class' => [guestBootstrap('$middleware->prepend(\App\Http\Middleware\RequireLogin::class);'), null, true],
    'non-literal append' => [guestBootstrap('$middleware->web(append: $stack);'), null, true],
    'guest aliased to another class' => [guestBootstrap('$middleware->alias(["guest" => \App\Http\Middleware\RequireLogin::class]);'), null, true],
    'configurator handed elsewhere' => [guestBootstrap('\App\Http\Kernel::configure($middleware);'), null, true],
    'unknown configurator call' => [guestBootstrap('$middleware->somethingNew([]);'), null, true],
    'withRouting then:' => ['<?php return Application::configure()->withRouting(then: function () { Route::middleware("auth")->group(base_path("routes/web.php")); })->create();', null, true],
    'missing bootstrap/app.php' => [null, null, true],
    'unparsable bootstrap/app.php' => ['<?php return Application::configure(', null, true],
    'provider wraps the route files in auth' => [guestBootstrap(''), 'Route::middleware("auth")->group(base_path("routes/web.php"));', true],
    'provider pushes auth to web' => [guestBootstrap(''), 'app("router")->pushMiddlewareToGroup("web", \Illuminate\Auth\Middleware\Authenticate::class);', true],
    'provider pushes PrepareKitRequest to web' => [guestBootstrap(''), 'app("router")->pushMiddlewareToGroup("web", \Tey\Kit\Features\SessionUi\Http\Middleware\PrepareKitRequest::class);', false],
    'provider loads a route file' => [guestBootstrap(''), '$this->loadRoutesFrom(__DIR__."/../../routes/extra.php");', true],
]);


test('a guest form passes through Inertia v3 flash reads, kit FormStatus flash-key, and top-level Inertia::flash calls', function (string $store, string $page, string $routes, array $checks) {
    file_put_contents($this->backendBase.'/routes/web.php', '<?php use Illuminate\Support\Facades\Route; use App\JoinPage; use App\JoinStore; '.$routes);
    file_put_contents($this->backendBase.'/bootstrap/app.php', guestBootstrap(''));
    @mkdir($this->backendBase.'/resources/js/pages/Public', 0777, true);
    file_put_contents($this->backendBase.'/resources/js/pages/Public/Join.vue', $page);
    $controllers = 'namespace App; use Inertia\Inertia; use Illuminate\Http\RedirectResponse; '
        .'class JoinPage { public function __invoke() { return Inertia::render("Public/Join"); } } '
        .'class JoinStore { public function __invoke($request, $key): RedirectResponse { '.$store.' } }';
    $found = array_column(($this->backend)('toast', $controllers, ['pageRoots' => ['resources/js/pages']], 'src/Controllers/Join.php'), 'check');
    expect($found)->toBe($checks);
})->with(function () {
    $routes = 'Route::get("/join", JoinPage::class); Route::post("/join", JoinStore::class)->middleware("throttle:6,1");';
    $view = fn (string $attributes, string $script = ''): string => "<script setup lang=\"ts\">\nimport { usePage } from '@inertiajs/vue3';\nimport { FormStatus } from '@/components/Kit';\n{$script}</script>\n<template>\n    <FormStatus {$attributes}/>\n</template>\n";
    $redirect = 'JoinStore::__invoke redirect must chain ->toast*()';
    $toastable = 'flash outcomes through Toastable, not session keys';
    $flash = 'if (! $request->filled("website")) { Interests::record($request); } Inertia::flash("joined", "thanks"); return back();';
    $read = $view(':message="$page.flash.joined" ');

    return [
        // The reported shape: an invokable store whose GET page is another controller.
        'Inertia::flash, read through $page.flash' => [$flash, $read, $routes, []],
        'Inertia::flash, GET page through Route::inertia' => [$flash, $read, 'Route::inertia("/join", "Public/Join"); Route::post("/join", JoinStore::class);', []],
        'Inertia::flash, page without FormStatus' => [$flash, "<template>\n    <form />\n</template>\n", $routes, [$redirect]],
        'Inertia::flash, key not read' => [$flash, $view(':message="$page.flash.other" '), $routes, [$redirect]],
        'Inertia::flash inside an if' => ['if ($request->filled("email")) { Inertia::flash("joined", "thanks"); } return back();', $read, $routes, [$redirect]],
        'Inertia::flash inside a try' => ['try { Inertia::flash("joined", "thanks"); } finally {} return back();', $read, $routes, [$redirect]],
        'Inertia::flash with a non-literal key' => ['Inertia::flash($key, "thanks"); return back();', $read, $routes, [$toastable, $redirect]],
        'Inertia::flash array, every key read' => ['Inertia::flash(["joined" => "thanks"]); return back();', $read, $routes, []],
        'Inertia::flash array with an unread key' => ['Inertia::flash(["joined" => "thanks", "ref" => "A-1"]); return back();', $read, $routes, [$redirect]],
        'Inertia::flash plus ->with, both read' => ['Inertia::flash("joined", "thanks"); return back()->with("ref", "A-1");', $view(':message="flash.joined ?? flash.ref" '), $routes, []],
        'Inertia::flash with an outcome key stays a Toastable violation' => ['Inertia::flash("status", "thanks"); return back();', $view(':message="$page.flash.status" '), $routes, [$toastable]],
        'Route::inertia with a non-literal page' => [$flash, $read, 'Route::inertia("/join", $page); Route::post("/join", JoinStore::class);', [$redirect]],
        // Inertia v3 read paths.
        '$page?.flash?.joined' => [$flash, $view(':message="$page?.flash?.joined" '), $routes, []],
        'usePage().flash.joined' => [$flash, $view(':message="usePage().flash.joined" '), $routes, []],
        'usePage()?.flash.joined, wrong key' => [$flash, $view(':message="usePage()?.flash.other" '), $routes, [$redirect]],
        'const page = usePage(), page.flash.joined' => [$flash, $view(':message="page.flash.joined" ', "const page = usePage();\n"), $routes, []],
        'let p = usePage(), p.flash?.joined' => [$flash, $view(':message="p.flash?.joined" ', "let p = usePage();\n"), $routes, []],
        'page.flash.joined with page not bound to usePage()' => [$flash, $view(':message="page.flash.joined" ', "const page = useSomethingElse();\n"), $routes, [$redirect]],
        'page.flash.other, wrong key' => [$flash, $view(':message="page.flash.other" ', "const page = usePage();\n"), $routes, [$redirect]],
        'page.value.flash is not read (usePage() is reactive, not a ref)' => [$flash, $view(':message="page.value.flash.joined" ', "const page = usePage();\n"), $routes, [$redirect]],
        // kit FormStatus flash-key prop.
        'static flash-key' => [$flash, $view('flash-key="joined" '), $routes, []],
        'static flashKey' => [$flash, $view('flashKey="joined" '), $routes, []],
        'bound flash-key, string literal' => [$flash, $view(":flash-key=\"'joined'\" "), $routes, []],
        'static flash-key, wrong key' => [$flash, $view('flash-key="other" '), $routes, [$redirect]],
        'bound flash-key, variable' => [$flash, $view(':flash-key="joined" ', "const joined = 'joined';\n"), $routes, [$redirect]],
        // Inertia v2 forms are still accepted.
        'v2 $page.props.flash.joined' => [$flash, $view(':message="$page.props.flash.joined" '), $routes, []],
        'v2 usePage().props?.flash?.joined' => [$flash, $view(':message="usePage().props?.flash?.joined" '), $routes, []],
        'v2 back()->with read through page.props.flash' => ['return back()->with("joined", "thanks");', $view(':message="page.props.flash.joined" ', "const page = usePage();\n"), $routes, []],
    ];
});
