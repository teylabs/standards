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
use Symfony\Component\Process\Process;
use Tey\Standards\StandardsServiceProvider;

$autoload = $argv[1] ?? dirname(__DIR__).'/vendor/autoload.php';
require $autoload;

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

if (! in_array('--hide-arch', $argv, true) && ! in_array('--with-dev', $argv, true)) {
    $guard = tempnam(sys_get_temp_dir(), 'standards-cli-guard-');
    file_put_contents($guard, <<<'PHP'
    <?php
    register_shutdown_function(static function (): void {
        foreach (['Illuminate\Foundation\Application', 'Pest\TestSuite', 'PhpParser\ParserFactory'] as $class) {
            if (class_exists($class, false)) {
                throw new RuntimeException('CLI loaded '.$class);
            }
        }
    });
    PHP);

    try {
        $binary = isset($argv[1]) ? dirname($autoload).'/bin/standards' : dirname(__DIR__).'/bin/standards';
        $process = new Process([PHP_BINARY, '-d', 'auto_prepend_file='.$guard, $binary, 'ci', '--json'], dirname(__DIR__));
        $process->mustRun();
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        if ($process->getErrorOutput() !== '' || $report['schema'] !== 1 || $report['findings'] !== [] || $report['exit_code'] !== 0) {
            throw new RuntimeException('Production CLI did not inspect the package cleanly: '.$process->getOutput().$process->getErrorOutput());
        }

        echo "PASS: standards ci --json without Pest, PHP-Parser or a loaded Laravel Application.\n";
    } finally {
        unlink($guard);
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
    'preflight' => ['ci' => ['distribution' => 'app']],
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

    if (in_array('--expect-no-pcntl', $argv, true)) {
        if ($exit !== 2 || $json['exit_code'] !== 2 || $json['results'] !== [] || ! str_contains($json['error'], 'Enable PHP CLI PCNTL')) {
            throw new RuntimeException('Preflight must refuse to run without real PCNTL: '.$stdout);
        }

        echo "PASS: provider boot without architecture dependencies; missing PCNTL refuses unbounded checks.\n";

        return;
    }

    $expected = ['app.key', 'app.env-debug', 'database.config', 'mail.transport', 'mail.from', 'mail.reply-to', 'queue.connection', 'queue.worker', 'cache.store', 'app.url', 'logging.channel', 'brand.favicon', 'ci.triggers'];

    if ($exit !== 0 || $json['exit_code'] !== 0 || array_column($json['results'], 'check') !== $expected
        || array_column($json['results'], 'status') !== [...array_fill(0, count($expected) - 1, 'pass'), 'skipped']) {
        throw new RuntimeException('Registered preflight did not pass deployment checks and skip CI: '.$stdout);
    }

    $log = file_get_contents($app->directory.'/preflight.log');

    if (substr_count($log, 'PREFLIGHT CHECK PASSED:') !== count($expected) - 1
        || ! str_contains($log, 'PREFLIGHT CHECK SKIPPED: ci.triggers')
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

    mkdir($app->directory.'/.github/workflows', 0777, true);
    $workflow = <<<'YAML'
    on:
      push:
        branches: [main]
      pull_request:
        types: [opened, synchronize, reopened, ready_for_review]
    concurrency:
      group: ${{ github.workflow }}-${{ github.event.pull_request.number || github.ref }}-${{ github.event.pull_request.draft }}
      cancel-in-progress: ${{ github.event_name == 'pull_request' }}
    jobs:
      tests:
        if: github.event_name != 'pull_request' || github.event.pull_request.draft == false
        runs-on: ubuntu-latest
        timeout-minutes: 10
        steps:
          - run: php artisan test
    YAML;
    $path = $app->directory.'/.github/workflows/tests.yml';
    file_put_contents($path, $workflow);
    $options = ['--ci' => true, '--only' => 'ci', '--strict' => true, '--json' => true, '--no-stderr' => true];

    if ($artisan->call('standards:preflight', $options) !== 0
        || json_decode($artisan->output(), true)['results'][0]['status'] !== 'pass') {
        throw new RuntimeException('CI YAML parsing failed without development dependencies.');
    }

    file_put_contents($path, str_replace('-${{ github.event.pull_request.draft }}', '', $workflow));
    $exit = $artisan->call('standards:preflight', $options);
    $stdout = $artisan->output();

    if ($exit !== 0 || json_decode($stdout, true)['results'][0]['status'] !== 'fail'
        || ! str_contains($stdout, 'concurrency-draft:') || ! str_contains($stdout, 'PREFLIGHT_CI_ENFORCE=true')) {
        throw new RuntimeException('Advisory CI did not report the draft concurrency finding without failing: '.$stdout);
    }

    $app['config']->set('preflight.ci.enforce', true);

    if ($artisan->call('standards:preflight', $options) !== 1
        || ! str_contains($artisan->output(), 'concurrency-draft:')) {
        throw new RuntimeException('Strict CI did not reject the draft concurrency finding.');
    }

    $app['config']->set('preflight.ci.enforce', false);
    file_put_contents($path, str_replace('    timeout-minutes: 10', '', $workflow));
    $exit = $artisan->call('standards:preflight', $options);
    $stdout = $artisan->output();

    if ($exit !== 0 || json_decode($stdout, true)['results'][0]['status'] !== 'fail'
        || ! str_contains($stdout, 'PREFLIGHT_CI_ENFORCE=true')) {
        throw new RuntimeException('Advisory CI did not report the timeout finding without failing: '.$stdout);
    }

    $app['config']->set('preflight.ci.enforce', true);

    if ($artisan->call('standards:preflight', $options) !== 1) {
        throw new RuntimeException('Strict CI did not reject the timeout finding.');
    }

    unlink($path);

    if ($artisan->call('standards:preflight', $options) !== 0
        || ! str_contains($artisan->output(), 'Unknown')) {
        throw new RuntimeException('Missing workflows did not remain advisory.');
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
    foreach ($app['log']->getChannels() as $logger) {
        $logger->getLogger()->close();
    }

    (new Filesystem)->deleteDirectory($app->directory);
}
