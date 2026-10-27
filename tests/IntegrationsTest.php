<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;
use Tey\Standards\Enforcement\CheckReport;
use Tey\Standards\Enforcement\Gate;
use Tey\Standards\Enforcement\Maker;
use Tey\Standards\Patterns\TreeCatalog;

beforeEach(function () {
    $this->integrationsBase = dirname(__DIR__).'/fixtures/IntegrationsRun'.bin2hex(random_bytes(6));
    foreach (['src/Http', 'src/Services', 'routes', 'config'] as $directory) {
        mkdir($this->integrationsBase.'/'.$directory, 0777, true);
    }
    $this->configuration = fn (array $options = []): Configuration => new Configuration(...array_replace(get_object_vars(fixtureConfiguration('Good')), ['base' => $this->integrationsBase, 'sourceRoots' => ['src'], 'routeRoots' => ['routes'], 'classRoots' => ['src' => 'App']], $options));
    $this->rule = fn (array $options = []): array => (new Conformance(($this->configuration)($options)))->violations('integrations');
    $this->put = fn (string $path, string $source) => file_put_contents($this->integrationsBase.'/'.$path, $source);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->integrationsBase);
});

test('a webhook route must reach a handler that verifies its sender', function () {
    ($this->put)('src/Http/PlainHook.php', "<?php namespace App\\Http; final class PlainHook { public function __invoke() { return response()->noContent(); } }");
    ($this->put)('src/Http/SignedHook.php', "<?php namespace App\\Http; final class SignedHook { public function __invoke(\$request) { abort_unless(hash_equals('a', (string) \$request->header('x')), 403); } }");
    ($this->put)('routes/webhooks.php', "<?php use App\\Http\\PlainHook; use App\\Http\\SignedHook; use Illuminate\\Support\\Facades\\Route;\nRoute::post('webhooks/plain', PlainHook::class);\nRoute::post('webhooks/signed', SignedHook::class);\nRoute::post('webhooks/cashier', [Laravel\\Cashier\\Http\\Controllers\\WebhookController::class, 'handleWebhook']);\nRoute::post('orders', PlainHook::class);\nRoute::webhooks('webhooks/spatie', 'acme');\nRoute::webhooks('webhooks/missing', 'nope');");
    ($this->put)('config/webhook-client.php', "<?php return ['configs' => [['name' => 'acme', 'signature_validator' => 'X'], ['name' => 'nope']]];");

    expect(array_column(($this->rule)(), 'check'))->toBe([
        'webhook route webhooks/plain does not go through a signature validator',
        'webhook route webhooks/missing has no signature validator configured for nope in config/webhook-client.php',
    ]);
});

test('inline Http:: is a finding outside a class that implements a contract, and a service provider is exempt', function () {
    ($this->put)('src/Services/Inline.php', "<?php namespace App\\Services; use Illuminate\\Support\\Facades\\Http; final class Inline { public function go() { return Http::get('https://x.test'); } }");
    ($this->put)('src/Services/Wrapped.php', "<?php namespace App\\Services; use Illuminate\\Support\\Facades\\Http; final class Wrapped implements Client { public function go() { return Http::get('https://x.test'); } }");
    ($this->put)('src/Services/AcmeServiceProvider.php', "<?php namespace App\\Services; use Illuminate\\Support\\Facades\\Http; use Illuminate\\Support\\ServiceProvider; final class AcmeServiceProvider extends ServiceProvider { public function boot(): void { Http::macro('acme', fn () => Http::baseUrl('x')); } }");

    expect(array_column(($this->rule)(), 'check'))->toBe(['App\Services\Inline calls Http:: inline: put the call behind a contract (an interface this class implements), with a fake for tests']);
});

test('the rule is advisory until an app opts in', function () {
    ($this->put)('src/Services/Inline.php', "<?php namespace App\\Services; use Illuminate\\Support\\Facades\\Http; final class Inline { public function go() { return Http::get('https://x.test'); } }");

    $advisory = (new Gate(($this->configuration)(), new DateTimeImmutable('2026-10-27')))->run(['src/Services/Inline.php']);
    expect(array_column($advisory['advisories'], 'rule'))->toBe(['integrations'])->and(array_column($advisory['violations'], 'rule'))->not->toContain('integrations');

    $enforced = (new Gate(($this->configuration)(['optionalRules' => ['integrations']]), new DateTimeImmutable('2026-10-27')))->run(['src/Services/Inline.php']);
    expect($enforced['advisories'])->toBe([])->and(array_column($enforced['violations'], 'rule'))->toContain('integrations');
});

test('standards:make webhook writes the provider files, the shared ones once, and merges config, routes, env and providers', function () {
    $base = fixtureApp();

    try {
        $maker = new Maker($base, TreeCatalog::shipped());
        $first = $maker->make('webhook', 'Acme', 'signed');
        mkdir($base.'/vendor/spatie/laravel-multitenancy', 0777, true);
        $second = $maker->make('webhook', 'Globex', 'signed');
        expect(file_get_contents($base.'/app/Webhooks/Globex/ProcessGlobexWebhook.php'))->toContain('extends ProcessWebhookJob implements NotTenantAware')->toContain('use Spatie\Multitenancy\Jobs\NotTenantAware;');
        (new Filesystem)->deleteDirectory($base.'/vendor/spatie/laravel-multitenancy');
        // The folder only marked the package present; the interface itself is not installed here, so the check below reads the job without it.
        $job = $base.'/app/Webhooks/Globex/ProcessGlobexWebhook.php';
        file_put_contents($job, str_replace([' implements NotTenantAware', "use Spatie\\Multitenancy\\Jobs\\NotTenantAware;\n"], '', file_get_contents($job)));

        expect($first)->toContain('app/Webhooks/Acme/AcmeSignatureValidator.php', 'app/Webhooks/Acme/AcmeWebhookProfile.php', 'app/Webhooks/Acme/ProcessAcmeWebhook.php', 'app/Webhooks/Acme/Handlers/HandleAcmeUpdated.php', 'tests/Feature/Webhooks/AcmeWebhookTest.php', 'app/Models/WebhookCall.php', 'app/Webhooks/ReceiveWebhook.php', 'app/Providers/WebhookServiceProvider.php', 'routes/webhooks.php', 'config/webhook-client.php', '.env.example', 'bootstrap/providers.php')
            ->and(array_filter($first, fn (string $file): bool => str_contains($file, '_add_event_id_to_webhook_calls_table')))->toHaveCount(1)
            ->and($second)->not->toContain('app/Models/WebhookCall.php', 'app/Providers/WebhookServiceProvider.php')
            ->and(array_filter($second, fn (string $file): bool => str_contains($file, 'webhook_calls')))->toBe([]);

        $config = (static fn (string $file): array => require $file)($base.'/config/webhook-client.php');
        expect(array_column($config['configs'], 'name'))->toBe(['globex', 'acme'])
            ->and($config['configs'][1]['signature_validator'])->toBe('App\Webhooks\Acme\AcmeSignatureValidator')
            ->and(file_get_contents($base.'/routes/webhooks.php'))->toContain("Route::post('webhooks/acme', ReceiveWebhook::class)->defaults('webhook', 'acme')->name('webhooks.acme');")->toContain("'webhooks/globex'")
            ->and(substr_count(file_get_contents($base.'/bootstrap/providers.php'), 'WebhookServiceProvider::class'))->toBe(1)
            ->and(file_get_contents($base.'/.env.example'))->toContain("ACME_WEBHOOK_SECRET=\n")->toContain('GLOBEX_WEBHOOK_SECRET=')
            ->and(file_get_contents($base.'/app/Webhooks/Acme/AcmeSignatureValidator.php'))->toContain('hash_equals(')->toContain('TOLERANCE_SECONDS')
            ->and(file_get_contents($base.'/app/Providers/WebhookServiceProvider.php'))->toContain("RateLimiter::for('webhooks'")->toContain("Route::middleware('throttle:webhooks')");
        // Without spatie/laravel-multitenancy the job is a plain one; with it (as Globex's app gets below), it is not tenant-aware.
        expect(file_get_contents($base.'/app/Webhooks/Acme/ProcessAcmeWebhook.php'))->toContain('final class ProcessAcmeWebhook extends ProcessWebhookJob' . "\n")->not->toContain('NotTenantAware');

        expect(fn () => $maker->make('webhook', 'Acme', 'signed'))->toThrow(InvalidArgumentException::class, 'already has a acme webhook');
        $report = CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-27'), TreeCatalog::shipped());
        expect($report['violations'])->toBe([])->and($report['advisories'])->toBe([]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('standards:make api-client writes the contract, the real client per flavor, the fake, tests, services keys and env lines', function () {
    $base = fixtureApp();

    try {
        $maker = new Maker($base, TreeCatalog::shipped());
        $http = $maker->make('api-client', 'Acme', 'http');
        $sdk = $maker->make('api-client', 'Globex', 'sdk');

        expect($http)->toContain('app/Services/Acme/AcmeClient.php', 'app/Services/Acme/HttpAcmeClient.php', 'app/Services/Acme/FakeAcmeClient.php', 'app/Exceptions/AcmeException.php', 'app/Providers/AcmeServiceProvider.php', 'tests/Feature/Services/AcmeClientTest.php', 'tests/Feature/Services/HttpAcmeClientTest.php', 'config/services.php', '.env.example', 'bootstrap/providers.php')
            ->and($sdk)->toContain('app/Services/Globex/SdkGlobexClient.php')->not->toContain('tests/Feature/Services/HttpGlobexClientTest.php')
            ->and(file_get_contents($base.'/app/Services/Acme/AcmeClient.php'))->toContain('standards-pattern: integrations.api-client.http')
            ->and(file_get_contents($base.'/app/Services/Globex/GlobexClient.php'))->toContain('standards-pattern: integrations.api-client')
            ->and(file_get_contents($base.'/app/Providers/AcmeServiceProvider.php'))->toContain("Http::macro('acme'")->toContain('->timeout(')->toContain('->retry(3')
            ->and(file_get_contents($base.'/app/Providers/GlobexServiceProvider.php'))->not->toContain('Http::');

        $services = (static fn (string $file): array => require $file)($base.'/config/services.php');
        expect(array_keys($services['acme']))->toBe(['base_url', 'token', 'timeout'])->and(array_keys($services['globex']))->toBe(['key'])
            ->and(file_get_contents($base.'/.env.example'))->toContain("ACME_BASE_URL=\nACME_TOKEN=\nACME_TIMEOUT=10")->toContain('GLOBEX_KEY=');
        expect(fn () => $maker->make('api-client', 'Acme', 'sdk'))->toThrow(InvalidArgumentException::class, 'never overwrites');

        $report = CheckReport::build($base, 'tests/Arch/configuration.php', null, 'full', new DateTimeImmutable('2026-10-27'), TreeCatalog::shipped());
        expect($report['violations'])->toBe([])->and($report['advisories'])->toBe([]);
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});

test('a provider list written with imports gets an import and the short name', function () {
    $base = fixtureApp();

    try {
        @mkdir($base.'/bootstrap', 0777, true);
        file_put_contents($base.'/bootstrap/providers.php', "<?php\n\nuse App\\Providers\\AppServiceProvider;\n\nreturn [\n    AppServiceProvider::class,\n];\n");
        (new Maker($base, TreeCatalog::shipped()))->make('api-client', 'Acme', 'http');

        expect(file_get_contents($base.'/bootstrap/providers.php'))->toBe("<?php\n\nuse App\\Providers\\AcmeServiceProvider;\nuse App\\Providers\\AppServiceProvider;\n\nreturn [\n    AppServiceProvider::class,\n    // standards-pattern: integrations.api-client.http\n    AcmeServiceProvider::class,\n];\n");
    } finally {
        (new Filesystem)->deleteDirectory($base);
    }
});
