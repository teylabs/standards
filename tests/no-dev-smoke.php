<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Config\Repository;
use Illuminate\Console\Application;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Contracts\Log\ContextLogProcessor as ContextProcessorContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Log\Context\ContextLogProcessor;
use Illuminate\Log\LogManager;
use Tey\Standards\StandardsServiceProvider;

require $argv[1] ?? dirname(__DIR__).'/vendor/autoload.php';

$hidden = ['Pest\\', 'PhpParser\\'];

if (in_array('--hide-arch', $argv, true)) {
    $loaders = spl_autoload_functions();

    foreach ($loaders as $loader) {
        spl_autoload_unregister($loader);
    }

    spl_autoload_register(static function (string $class) use ($loaders, $hidden): void {
        foreach ($hidden as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return;
            }
        }

        foreach ($loaders as $loader) {
            $loader($class);
        }
    });
} else {
    foreach (['pestphp/pest', 'pestphp/pest-plugin-arch', 'nikic/php-parser'] as $package) {
        if (InstalledVersions::isInstalled($package)) {
            throw new RuntimeException('Production install includes '.$package);
        }
    }
}

foreach (['Pest\\TestSuite', 'Pest\\Arch\\SingleArchExpectation', 'PhpParser\\ParserFactory'] as $class) {
    if (class_exists($class)) {
        throw new RuntimeException('Architecture class is autoloadable: '.$class);
    }
}

$app = new class extends Container
{
    public string $directory;

    public function basePath(): string
    {
        return $this->directory;
    }

    public function storagePath(string $path = ''): string
    {
        return $this->directory.'/'.$path;
    }

    public function configPath(string $path = ''): string
    {
        return $this->directory.'/config/'.$path;
    }

    public function configurationIsCached(): bool
    {
        return false;
    }

    public function runningInConsole(): bool
    {
        return true;
    }

    public function runningUnitTests(): bool
    {
        return true;
    }
};
$app->directory = sys_get_temp_dir().'/standards-no-dev-'.bin2hex(random_bytes(6));
$app->instance('config', new Repository([
    'app' => ['env' => 'production', 'debug' => false, 'key' => 'smoke-key', 'url' => 'https://app.test'],
    'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]],
    'mail' => ['default' => 'log', 'mailers' => ['log' => ['transport' => 'log']]],
    'queue' => ['default' => 'database'],
    'cache' => ['default' => 'file'],
    'logging' => ['default' => 'single', 'channels' => ['single' => ['driver' => 'single', 'path' => $app->directory.'/preflight.log']]],
]));
$app->alias('config', ConfigContract::class);
$app->instance('events', new Dispatcher($app));
$app->bind(ContextProcessorContract::class, ContextLogProcessor::class);
$app->instance('log', new LogManager($app));
$app->alias('log', LogManager::class);
Container::setInstance($app);
putenv('PREFLIGHT_SKIP');

try {
    $provider = new StandardsServiceProvider($app);
    $provider->register();
    $provider->boot();
    $artisan = new Application($app, $app['events'], 'smoke');
    $exit = $artisan->call('standards:preflight', ['--json' => true]);
    $json = json_decode($artisan->output(), true, flags: JSON_THROW_ON_ERROR);
    $expected = ['app.key', 'app.env-debug', 'database.config', 'mail.transport', 'mail.from', 'queue.connection', 'cache.store', 'app.url', 'logging.channel'];

    if ($exit !== 0 || $json['exit_code'] !== 0 || array_column($json['results'], 'check') !== $expected
        || array_unique(array_column($json['results'], 'status')) !== ['pass']) {
        throw new RuntimeException('Registered preflight did not pass all core checks: '.$artisan->output());
    }

    $app['config']->set('app.key', null);

    if ($artisan->call('standards:preflight', ['--json' => true]) !== 1) {
        throw new RuntimeException('A missing application key did not block deployment.');
    }

    if ($artisan->call('standards:preflight', ['--ci' => true, '--strict' => true, '--json' => true]) !== 0) {
        throw new RuntimeException('CI preflight did not skip the missing secret.');
    }

    if ($artisan->call('standards:preflight', ['--skip' => 'app.key', '--json' => true]) !== 0
        || ! is_file($app->storagePath('framework/preflight/app.key.json'))) {
        throw new RuntimeException('Emergency skip was not persisted.');
    }

    $app['config']->set('app.key', 'fixed');

    if ($artisan->call('standards:preflight', ['--json' => true]) !== 0
        || is_file($app->storagePath('framework/preflight/app.key.json'))) {
        throw new RuntimeException('Passing preflight did not clear the emergency skip.');
    }

    echo "PASS: provider boot and preflight without Pest or PHP-Parser; all nine checks, blocking failure, CI and skip recovery.\n";
} finally {
    (new Filesystem)->deleteDirectory($app->directory);
}
