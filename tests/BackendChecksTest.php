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
    $entries = array_map(fn ($finding) => ['rule' => $rule, ...$finding, 'reason' => 'Seeded backend debt', 'date' => '2026-10-24'], $bad);
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
