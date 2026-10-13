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
use Symfony\Component\Console\Tester\CommandTester;
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
} elseif (! in_array('--with-dev', $argv, true)) {
    foreach (['pestphp/pest', 'pestphp/pest-plugin-arch', 'nikic/php-parser'] as $package) {
        if (InstalledVersions::isInstalled($package)) {
            throw new RuntimeException('Production install includes '.$package);
        }
    }
}

foreach (in_array('--with-dev', $argv, true) ? [] : ['Pest\\TestSuite', 'Pest\\Arch\\SingleArchExpectation', 'PhpParser\\ParserFactory'] as $class) {
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
    'queue' => ['default' => 'sync'],
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
    mkdir($app->directory.'/public', 0777, true);
    mkdir($app->directory.'/resources/views', 0777, true);
    file_put_contents($app->directory.'/public/favicon.ico', pack('vvv', 0, 1, 2).pack('CCCCvvVV', 16, 16, 0, 0, 1, 32, 0, 38).pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, 0, 38));
    file_put_contents($app->directory.'/public/favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    file_put_contents($app->directory.'/public/apple-touch-icon.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAALQAAAC0CAAAAAAYplnuAAAANklEQVR4nO3BMQEAAADCoPVPbQlPoAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADgY39EAAGTgIAHAAAAAElFTkSuQmCC'));
    file_put_contents($app->directory.'/resources/views/app.blade.php', '<link rel="icon" href="/favicon.ico">');
    $provider = new StandardsServiceProvider($app);
    $provider->register();
    $provider->boot();
    $artisan = new Application($app, $app['events'], 'smoke');
    $exit = $artisan->call('standards:preflight', ['--json' => true, '--no-stderr' => true]);
    $stdout = $artisan->output();
    $json = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    $expected = ['app.key', 'app.env-debug', 'database.config', 'mail.transport', 'mail.from', 'mail.reply-to', 'queue.connection', 'queue.worker', 'cache.store', 'app.url', 'logging.channel', 'brand.favicon'];

    if ($exit !== 0 || $json['exit_code'] !== 0 || array_column($json['results'], 'check') !== $expected
        || array_unique(array_column($json['results'], 'status')) !== ['pass']) {
        throw new RuntimeException('Registered preflight did not pass all core checks: '.$stdout);
    }

    $log = file_get_contents($app->directory.'/preflight.log');

    if (substr_count($log, 'PREFLIGHT CHECK PASSED:') !== count($expected)
        || ! str_contains($log, 'PREFLIGHT SUMMARY {"exit_code":0,')
        || ! str_contains($log, '"check":"app.key","level":"blocking","status":"pass","message":')) {
        throw new RuntimeException('Preflight did not log every check and its summary.');
    }

    $tester = new CommandTester($artisan->find('standards:preflight'));
    $tester->execute(['--json' => true, '--stderr' => true], ['capture_stderr_separately' => true]);

    if ($tester->getDisplay() !== $stdout || ! str_contains($tester->getErrorOutput(), 'PREFLIGHT SUMMARY ')) {
        throw new RuntimeException('Stderr reporting changed JSON stdout or omitted its summary.');
    }

    $tester->execute(['--json' => true, '--no-stderr' => true], ['capture_stderr_separately' => true]);

    if ($tester->getErrorOutput() !== '') {
        throw new RuntimeException('Explicit --no-stderr did not suppress reporting.');
    }

    $app['config']->set(['queue.default' => 'async', 'queue.connections.async.driver' => 'database']);

    if ($artisan->call('standards:preflight', ['--only' => 'queue', '--strict' => true, '--json' => true]) !== 0
        || ! str_contains($artisan->output(), 'Unknown')) {
        throw new RuntimeException('Unavailable queue storage did not remain advisory without development dependencies.');
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

    echo "PASS: provider boot and preflight without Pest or PHP-Parser; all core checks, blocking failure, CI, skip recovery, logging and stderr.\n";
} finally {
    (new Filesystem)->deleteDirectory($app->directory);
}
