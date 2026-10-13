<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Contracts\Log\ContextLogProcessor as ContextProcessorContract;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Log\Context\ContextLogProcessor;
use Illuminate\Log\LogManager;
use Illuminate\Support\Carbon;
use Monolog\Formatter\JsonFormatter;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tey\Standards\Preflight;
use Tey\Standards\Preflight\Check;
use Tey\Standards\Preflight\CoreCheck;
use Tey\Standards\Preflight\Level;
use Tey\Standards\Preflight\PreflightCommand;
use Tey\Standards\Preflight\Result;
use Tey\Standards\StandardsServiceProvider;

if (! function_exists('pcntl_alarm')) {
    require __DIR__.'/Support/pcntl.php';
}

function preflightPng(int $width, int $height): string
{
    $chunk = fn (string $type, string $bytes): string => pack('N', strlen($bytes)).$type.$bytes.pack('N', crc32($type.$bytes));

    return "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
        .$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\0", $width), $height)))
        .$chunk('IEND', '');
}

function preflightWorld(): Container
{
    $app = new class extends Container
    {
        public string $directory;

        public string $root;

        public function storagePath(string $path = ''): string
        {
            return $this->directory.'/'.$path;
        }

        public function basePath(): string
        {
            return $this->root;
        }

        public function configurationIsCached(): bool
        {
            return false;
        }

        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $app->directory = sys_get_temp_dir().'/preflight-'.bin2hex(random_bytes(6));
    $app->root = $app->directory;
    mkdir($app->root.'/public', 0777, true);
    mkdir($app->root.'/resources/views', 0777, true);
    file_put_contents($app->root.'/public/favicon.ico', pack('vvv', 0, 1, 2).pack('CCCCvvVV', 16, 16, 0, 0, 1, 32, 0, 38).pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, 0, 38));
    file_put_contents($app->root.'/public/favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="16" height="16"/></svg>');
    file_put_contents($app->root.'/public/apple-touch-icon.png', preflightPng(180, 180));
    file_put_contents($app->root.'/resources/views/app.blade.php', '<link rel="icon" href="/favicon.svg">');
    $config = new Repository([
        'app' => ['env' => 'production', 'debug' => false, 'key' => 'test-key', 'url' => 'https://app.test'],
        'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        'mail' => ['default' => 'log', 'mailers' => ['log' => ['transport' => 'log']], 'from' => ['address' => 'alerts@app.test'], 'reply_to' => ['address' => 'hello@app.test']],
        'queue' => ['default' => 'sync'],
        'cache' => ['default' => 'file'],
        'logging' => ['default' => 'single', 'channels' => ['single' => ['driver' => 'single', 'path' => $app->directory.'/default.log']]],
    ]);
    $app->instance('config', $config);
    $app->alias('config', ConfigContract::class);
    $app->instance('events', new Dispatcher($app));
    $app->bind(ContextProcessorContract::class, ContextLogProcessor::class);
    $app->instance('log', new LogManager($app));
    $app->alias('log', LogManager::class);
    (new StandardsServiceProvider($app))->register();

    return $app;
}

function preflightCommand(Container $app, array $options = []): CommandTester
{
    $command = new PreflightCommand;
    $command->setLaravel($app);
    $tester = new CommandTester($command);
    $tester->execute($options, ['capture_stderr_separately' => true]);

    return $tester;
}

function preflightResults(CommandTester $tester): array
{
    return array_column(json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['results'], null, 'check');
}

function preflightInstalledPackages(array $data): void
{
    InstalledVersions::reload($data);
    // reload() also reads registered vendors; isolate their metadata for this fixture.
    (new ReflectionProperty(InstalledVersions::class, 'installedByVendor'))
        ->setValue(null, array_fill_keys(array_keys(ClassLoader::getRegisteredLoaders()), $data));
}

function preflightDatabase(Container $app): DatabaseManager
{
    $app['config']->set([
        'database.connections.backlog' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'queue.default' => 'async',
        'queue.connections.async' => ['driver' => 'database', 'connection' => 'backlog', 'table' => 'worker_jobs', 'queue' => 'notifications'],
    ]);
    $database = new DatabaseManager($app, new ConnectionFactory($app));
    $app->instance('db', $database);
    $database->connection('backlog')->getSchemaBuilder()->create('worker_jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue');
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('reserved_at')->nullable();
    });

    return $database;
}

beforeEach(function () {
    $this->previousContainer = Container::getInstance();
    $this->skipEnvironment = getenv('PREFLIGHT_SKIP');
    putenv('PREFLIGHT_SKIP');
    $this->preflight = preflightWorld();
    Container::setInstance($this->preflight);
});

afterEach(function () {
    foreach ($this->preflight['log']->getChannels() as $logger) {
        $logger->getLogger()->close();
    }

    (new Filesystem)->deleteDirectory($this->preflight->directory);
    Container::setInstance($this->previousContainer);
    $this->skipEnvironment === false ? putenv('PREFLIGHT_SKIP') : putenv('PREFLIGHT_SKIP='.$this->skipEnvironment);
});

test('the JSON contract lists every core check with its reviewed level and exits zero', function () {
    $tester = preflightCommand($this->preflight, ['--json' => true]);
    $json = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($json))->toBe(['schema', 'results', 'exit_code']);
    expect($json['schema'])->toBe(1)->and($json['exit_code'])->toBe(0)->and($tester->getStatusCode())->toBe(0);
    $expected = ['app.key' => 'blocking', 'app.env-debug' => 'blocking', 'database.config' => 'blocking', 'mail.transport' => 'warning', 'mail.from' => 'warning', 'mail.reply-to' => 'warning', 'queue.connection' => 'warning', 'queue.worker' => 'warning', 'cache.store' => 'warning', 'app.url' => 'warning', 'logging.channel' => 'warning', 'brand.favicon' => 'warning', 'ci.triggers' => 'warning'];

    foreach ($json['results'] as $result) {
        expect(array_keys($result))->toBe(['check', 'level', 'status', 'message']);
        expect($result['status'])->toBe($result['check'] === 'ci.triggers' ? 'skipped' : 'pass')->and($result['level'])->toBe($expected[$result['check']])->and($result['message'])->not->toBeEmpty();
    }

    expect(array_column($json['results'], 'check'))->toBe(array_keys($expected));
});

test('the provider boots and registers a working command without autoloadable architecture dependencies', function () {
    $autoload = array_key_first(ClassLoader::getRegisteredLoaders()).'/autoload.php';
    $process = new Process([PHP_BINARY, __DIR__.'/no-dev-smoke.php', $autoload, '--hide-arch']);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    expect($process->getOutput())->toContain('PASS: provider boot and preflight without Pest or PHP-Parser');
})->skip(PHP_OS_FAMILY === 'Windows', '2026-10-13: the deployment smoke test requires PCNTL alarms, unavailable on Windows.');

test('the real command refuses unbounded execution without PCNTL', function () {
    $autoload = array_key_first(ClassLoader::getRegisteredLoaders()).'/autoload.php';
    $process = new Process([PHP_BINARY, '-d', 'disable_functions=pcntl_alarm,pcntl_async_signals', __DIR__.'/no-dev-smoke.php', $autoload, '--hide-arch', '--expect-no-pcntl']);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    expect($process->getOutput())->toContain('missing PCNTL refuses unbounded checks.');
});

test('each failure has the specified level and exit code', function (string $check, array $changes, string $level, int $exit) {
    $this->preflight['config']->set(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'smtp.app.test']]);
    $this->preflight['config']->set($changes);
    $tester = preflightCommand($this->preflight, ['--json' => true]);
    expect(preflightResults($tester)[$check])->toMatchArray(['level' => $level, 'status' => 'fail']);
    expect($tester->getStatusCode())->toBe($exit);
})->with([
    ['app.key', ['app.key' => '  '], 'blocking', 1],
    ['app.env-debug', ['app.debug' => true], 'blocking', 1],
    ['database.config', ['database.default' => 'missing'], 'blocking', 1],
    ['database.config', ['database.connections.sqlite.database' => null], 'blocking', 1],
    ['mail.transport', ['mail.default' => 'missing'], 'warning', 0],
    ['mail.from', ['mail.from.address' => null], 'warning', 0],
    ['mail.from', ['mail.from.address' => null, 'app.env' => 'local'], 'warning', 0],
    ['mail.from', ['mail.from.address' => 'hello@example.com'], 'warning', 0],
    ['mail.from', ['mail.from.address' => 'hello@sub.example.com'], 'warning', 0],
    ['mail.from', ['mail.from.address' => 'invalid'], 'warning', 0],
    ['cache.store', ['cache.default' => 'array'], 'warning', 0],
    ['app.url', ['app.url' => 'http://app.test'], 'warning', 0],
    ['app.url', ['app.url' => 'https:///'], 'warning', 0],
    ['app.url', ['app.url' => ''], 'warning', 0],
    ['logging.channel', ['logging.default' => 'missing'], 'warning', 0],
]);

test('sender advisories are logged, reported and retain skip records even in strict mode', function () {
    $this->preflight['config']->set(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'smtp.app.test'], 'mail.from.address' => 'INFO@parent.test', 'mail.shared_parent_domain' => 'parent.test', 'mail.reply_to.address' => 'hello@parent.test']);
    preflightCommand($this->preflight, ['--skip' => 'mail.from', '--json' => true]);

    $advisory = preflightCommand($this->preflight, ['--strict' => true, '--json' => true]);
    expect($advisory->getStatusCode())->toBe(0);
    expect(preflightResults($advisory)['mail.from'])->toMatchArray(['level' => 'warning', 'status' => 'warn']);
    expect(file_get_contents($this->preflight->directory.'/default.log'))->toContain('PREFLIGHT CHECK WARNING: mail.from');
    $path = $this->preflight->directory.'/framework/preflight/mail.from.json';
    expect(file_exists($path))->toBeTrue();

    $this->preflight['config']->set('mail.from.address', null);
    $failure = preflightCommand($this->preflight, ['--json' => true]);
    expect($failure->getStatusCode())->toBe(0);
    expect(preflightResults($failure)['mail.from'])->toMatchArray(['level' => 'warning', 'status' => 'fail']);
    expect(file_get_contents($this->preflight->directory.'/default.log'))->toContain('PREFLIGHT CHECK FAILED: mail.from');
    expect(file_exists($path))->toBeTrue();

    $this->preflight['config']->set('mail.from.address', 'brand@parent.test');
    expect(preflightCommand($this->preflight, ['--strict' => true])->getStatusCode())->toBe(0);
    expect(file_exists($path))->toBeFalse();
});

test('production-only warnings permit local defaults and distinct senders', function () {
    $this->preflight['config']->set(['app.env' => 'local', 'app.debug' => true, 'app.url' => 'http://localhost', 'queue.default' => 'sync', 'cache.default' => 'array', 'mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'smtp.app.test'], 'mail.from.address' => 'hello@child.parent.test', 'mail.shared_parent_domain' => 'parent.test', 'mail.reply_to.address' => 'hello@parent.test']);
    $tester = preflightCommand($this->preflight, ['--json' => true]);
    expect(array_values(array_unique(array_column(preflightResults($tester), 'status'))))->toBe(['pass', 'skipped']);
});

test('API mail checks require both an installed package and its resolved config key', function (string $transport, string $package, string $key, string $env) {
    $original = InstalledVersions::getRawData();
    $data = $original;
    $this->preflight['config']->set(['mail.default' => 'transactional', 'mail.mailers.transactional' => ['transport' => $transport]]);

    try {
        $data['versions']['symfony/http-client'] = ['version' => '1.0.0', 'dev_requirement' => false];
        unset($data['versions'][$package]);
        preflightInstalledPackages($data);
        $missing = preflightCommand($this->preflight, ['--strict' => true, '--json' => true]);
        expect($missing->getStatusCode())->toBe(1)->and(preflightResults($missing)['mail.transport']['message'])->toContain($package);
        expect(preflightCommand($this->preflight, ['--json' => true])->getStatusCode())->toBe(0);
        $data['versions'][$package] = ['version' => '1.0.0', 'dev_requirement' => false];
        preflightInstalledPackages($data);
        $missingKey = preflightCommand($this->preflight, ['--strict' => true, '--json' => true]);
        expect($missingKey->getStatusCode())->toBe(1)->and(preflightResults($missingKey)['mail.transport']['message'])->toContain($env);
        $this->preflight['config']->set([$key => 'secret-not-for-output', 'services.mailgun.domain' => 'mail.app.test', 'services.ses.secret' => 'private']);
        $passes = preflightCommand($this->preflight, ['--json' => true]);
        expect(preflightResults($passes)['mail.transport']['status'])->toBe('pass')->and($passes->getDisplay())->not->toContain('secret-not-for-output');

        if (in_array($transport, ['postmark', 'mailgun'], true)) {
            unset($data['versions']['symfony/http-client']);
            preflightInstalledPackages($data);
            $missingClient = preflightCommand($this->preflight, ['--strict' => true, '--json' => true]);
            expect($missingClient->getStatusCode())->toBe(1)->and(preflightResults($missingClient)['mail.transport']['message'])->toContain('symfony/http-client');
        }
    } finally {
        InstalledVersions::reload($original);
    }
})->with([
    ['resend', 'resend/resend-php', 'services.resend.key', 'RESEND_API_KEY'],
    ['postmark', 'symfony/postmark-mailer', 'services.postmark.key', 'POSTMARK_API_KEY'],
    ['mailgun', 'symfony/mailgun-mailer', 'services.mailgun.secret', 'MAILGUN_SECRET'],
    ['ses', 'aws/aws-sdk-php', 'services.ses.key', 'AWS_ACCESS_KEY_ID'],
    ['ses-v2', 'aws/aws-sdk-php', 'services.ses.key', 'AWS_ACCESS_KEY_ID'],
]);

test('mail credentials follow Laravel mailer and service config precedence', function (string $transport, string $package, array $mailer, array $services, string $status) {
    $original = InstalledVersions::getRawData();
    $data = $original;
    foreach ([$package, 'symfony/http-client'] as $installed) {
        $data['versions'][$installed] = ['version' => '1.0.0', 'dev_requirement' => false];
    }

    try {
        preflightInstalledPackages($data);
        $this->preflight['config']->set(['mail.default' => 'api', 'mail.mailers.api' => ['transport' => $transport, ...$mailer], 'services' => $services]);
        expect(preflightResults(preflightCommand($this->preflight, ['--json' => true]))['mail.transport']['status'])->toBe($status);
    } finally {
        InstalledVersions::reload($original);
    }
})->with([
    ['postmark', 'symfony/postmark-mailer', ['token' => '', 'key' => 'mailer-key'], ['postmark' => ['key' => 'service-key']], 'fail'],
    ['postmark', 'symfony/postmark-mailer', ['token' => 'mailer-token', 'key' => ''], [], 'pass'],
    ['postmark', 'symfony/postmark-mailer', [], ['postmark' => ['token' => '', 'key' => 'service-key']], 'fail'],
    ['mailgun', 'symfony/mailgun-mailer', ['secret' => null, 'domain' => ''], ['mailgun' => ['secret' => 'service-secret', 'domain' => 'app.test']], 'pass'],
    ['mailgun', 'symfony/mailgun-mailer', ['secret' => 'mailer-secret'], ['mailgun' => ['secret' => 'service-secret', 'domain' => 'app.test']], 'fail'],
    ['mailgun', 'symfony/mailgun-mailer', ['domain' => 'app.test'], ['mailgun' => ['secret' => 'service-secret', 'domain' => '']], 'fail'],
    ['ses', 'aws/aws-sdk-php', ['key' => null], ['ses' => ['key' => 'service-key', 'secret' => 'service-secret']], 'fail'],
    ['ses-v2', 'aws/aws-sdk-php', ['secret' => null], ['ses' => ['key' => 'service-key', 'secret' => 'service-secret']], 'fail'],
]);

test('composite mailers validate every child and reject cycles', function () {
    $this->preflight['config']->set(['mail.default' => 'failover', 'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['log', 'api']], 'mail.mailers.api' => ['transport' => 'resend']]);
    $tester = preflightCommand($this->preflight, ['--json' => true]);
    expect(preflightResults($tester)['mail.transport']['status'])->toBe('fail');
    $this->preflight['config']->set('mail.mailers.failover.mailers', ['failover']);
    expect(preflightResults(preflightCommand($this->preflight, ['--json' => true]))['mail.transport']['status'])->toBe('fail');
});

test('CI skips secret checks but still blocks non-secret failures', function () {
    $this->preflight['config']->set(['app.key' => null, 'app.debug' => true, 'mail.default' => 'api', 'mail.mailers.api' => ['transport' => 'resend']]);
    $tester = preflightCommand($this->preflight, ['--ci' => true, '--json' => true]);
    $results = preflightResults($tester);
    expect($results['app.key']['status'])->toBe('skipped-in-ci')->and($results['mail.transport']['status'])->toBe('skipped-in-ci');
    expect($results['app.env-debug']['status'])->toBe('fail')->and($tester->getStatusCode())->toBe(1);
    expect(glob($this->preflight->directory.'/framework/preflight/*.json'))->toBe([]);
});

test('skips print a banner, log, persist and clear only after passing', function (bool $dedicated) {
    $this->preflight->root = dirname(__DIR__);

    if ($dedicated) {
        $this->preflight['config']->set('logging.channels.standards', ['driver' => 'single', 'path' => $this->preflight->directory.'/standards.log']);
    }

    $this->preflight['config']->set('app.key', null);
    putenv('PREFLIGHT_SKIP=app.key');
    $tester = preflightCommand($this->preflight, ['--skip' => ' mail.from,app.key ', '--json' => true, '--ci' => true]);
    expect($tester->getStatusCode())->toBe(0)->and($tester->getErrorOutput())->toContain('!!! PREFLIGHT CHECK SKIPPED: app.key');
    expect(preflightResults($tester)['app.key']['status'])->toBe('skipped-by-request');
    $path = $this->preflight->directory.'/framework/preflight/app.key.json';
    $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($record)->toMatchArray(['check' => 'app.key', 'reason' => 'Emergency skip requested by --skip.']);
    expect(new DateTimeImmutable($record['time']))->toBeInstanceOf(DateTimeImmutable::class);
    expect($record['commit'])->toMatch('/^[a-f0-9]{40}$/');
    expect(file_get_contents($this->preflight->directory.($dedicated ? '/standards.log' : '/default.log')))->toContain('PREFLIGHT CHECK SKIPPED: app.key');
    putenv('PREFLIGHT_SKIP');
    preflightCommand($this->preflight, ['--ci' => true]);
    expect(file_exists($path))->toBeTrue();
    preflightCommand($this->preflight);
    expect(file_exists($path))->toBeTrue();
    $this->preflight['config']->set('app.key', 'fixed');
    preflightCommand($this->preflight);
    expect(file_exists($path))->toBeFalse();
})->with([false, true]);

test('process and cached skip settings are combined with CLI skips', function (bool $process) {
    $process ? putenv('PREFLIGHT_SKIP=app.key') : $this->preflight['config']->set('preflight.skip', 'app.key');
    $tester = preflightCommand($this->preflight, ['--skip' => 'mail.from']);
    expect($tester->getDisplay())->toContain('PREFLIGHT CHECK SKIPPED: app.key', 'PREFLIGHT CHECK SKIPPED: mail.from', 'skipped-by-request');
})->with([true, false]);

test('unknown skips fail before any record is written', function (bool $environment, string $unknown) {
    if ($environment) {
        putenv('PREFLIGHT_SKIP='.$unknown);
    }

    $tester = preflightCommand($this->preflight, ['--skip' => $environment ? 'app.key' : 'app.key,'.$unknown, '--json' => true]);
    expect($tester->getStatusCode())->toBe(2)->and($tester->getDisplay())->toContain('Unknown skip names: '.$unknown);
    expect(json_decode($tester->getDisplay(), true)['results'])->toBe([]);
    expect(glob($this->preflight->directory.'/framework/preflight/*.json'))->toBe([]);
})->with([[true, 'mail.typo'], [false, 'mail.typo'], [true, '0'], [false, '0']]);

test('apps can register tagged checks with timeouts and secret-safe exception output', function (string $mode, Level $level, int $exit) {
    if ($mode === 'timeout' && ! function_exists('pcntl_alarm')) {
        $this->markTestSkipped('2026-10-13: interrupting a busy custom check requires real PCNTL alarms, unavailable on Windows.');
    }

    $check = new class($mode, $level) implements Check
    {
        public function __construct(private string $mode, private Level $severity) {}

        public function name(): string
        {
            return 'app.custom';
        }

        public function level(): Level
        {
            return $this->severity;
        }

        public function needsSecrets(): bool
        {
            return false;
        }

        public function run(): Result
        {
            if ($this->mode === 'exception') {
                throw new RuntimeException('secret-must-not-leak');
            }
            if ($this->mode === 'timeout') {
                while (true) {
                    hrtime(true);
                }
            }

            return Result::fail('Set the app-specific setting.');
        }
    };
    $this->preflight->instance($check::class, $check);
    if ($mode === 'failure') {
        $this->preflight->tag([$check::class], 'standards.preflight');
    } else {
        Preflight::register($check::class);
    }
    $this->preflight['config']->set('preflight.timeout', 1);
    $started = microtime(true);
    $tester = preflightCommand($this->preflight, ['--json' => true]);
    expect($tester->getStatusCode())->toBe($exit)->and($tester->getDisplay())->not->toContain('secret-must-not-leak');
    expect(preflightResults($tester)['app.custom']['status'])->toBe('fail');
    if ($mode === 'timeout') {
        expect(microtime(true) - $started)->toBeLessThan(3)->and($tester->getDisplay())->toContain('timed out after 1s');
    }
})->with([
    ['failure', Level::Warning, 0], ['failure', Level::Blocking, 1], ['exception', Level::Blocking, 1], ['timeout', Level::Blocking, 1],
]);

test('invalid timeout configuration is a usage error', function () {
    $this->preflight['config']->set('preflight.timeout', 0);
    expect(preflightCommand($this->preflight)->getStatusCode())->toBe(2);
});

test('strict group selection includes registered checks but excludes unrelated failures', function (bool $advisory) {
    $check = new class($advisory) implements Check
    {
        public function __construct(private bool $advisory) {}

        public function name(): string
        {
            return 'mail.custom';
        }

        public function level(): Level
        {
            return Level::Warning;
        }

        public function needsSecrets(): bool
        {
            return false;
        }

        public function run(): Result
        {
            return $this->advisory ? Result::warn('Review the custom mail setting.') : Result::fail('Configure the custom mail requirement.');
        }
    };
    $this->preflight->instance($check::class, $check);
    Preflight::register($check::class);
    $this->preflight['config']->set('app.key', null);
    $tester = preflightCommand($this->preflight, ['--only' => 'mail', '--strict' => true, '--json' => true]);
    expect(array_keys(preflightResults($tester)))->toBe(['mail.transport', 'mail.from', 'mail.reply-to', 'mail.custom']);
    expect(preflightResults($tester)['mail.custom'])->toMatchArray(['level' => 'warning', 'status' => $advisory ? 'warn' : 'fail']);
    expect($tester->getStatusCode())->toBe($advisory ? 0 : 1);

    $unknown = preflightCommand($this->preflight, ['--only' => 'mai', '--skip' => 'mail.from', '--json' => true]);
    expect($unknown->getStatusCode())->toBe(2)->and(json_decode($unknown->getDisplay(), true)['results'])->toBe([]);
    expect(glob($this->preflight->directory.'/framework/preflight/*.json'))->toBe([]);
})->with([false, true]);

test('reply-to validates addresses and the last two domain labels without blocking deployment', function (?string $replyTo, string $from, string $status) {
    $this->preflight['config']->set([
        'mail.default' => 'smtp',
        'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'smtp.app.test'],
        'mail.from.address' => $from,
        'mail.reply_to.address' => $replyTo,
    ]);

    $deployment = preflightCommand($this->preflight, ['--json' => true]);
    expect($deployment->getStatusCode())->toBe(0);
    expect(preflightResults($deployment)['mail.reply-to'])->toMatchArray(['level' => 'warning', 'status' => $status]);

    $strict = preflightCommand($this->preflight, ['--only' => 'mail', '--strict' => true, '--ci' => true, '--json' => true]);
    expect($strict->getStatusCode())->toBe($status === 'pass' ? 0 : 1);
    expect(preflightResults($strict)['mail.reply-to'])->toMatchArray(['level' => 'warning', 'status' => $status]);

    if ($status === 'fail') {
        expect(preflightResults($strict)['mail.reply-to']['message'])->toContain('MAIL_REPLY_TO_ADDRESS', 'mail.reply_to.address');
    }
})->with([
    'unset' => [null, 'updates@app.test', 'fail'],
    'empty' => ['', 'updates@app.test', 'fail'],
    'blank' => ['   ', 'updates@app.test', 'fail'],
    'invalid' => ['not-an-email', 'updates@app.test', 'fail'],
    'different domain' => ['hello@other.test', 'updates@app.test', 'fail'],
    'deceptive subdomain' => ['hello@app.test.other.test', 'updates@app.test', 'fail'],
    'hello' => ['hello@app.test', 'updates@app.test', 'pass'],
    'support' => ['support@app.test', 'updates@app.test', 'pass'],
    'case insensitive' => ['Support@APP.TEST', 'updates@App.Test', 'pass'],
    'subdomain sender' => ['hello@app.test', 'updates@mail.app.test', 'pass'],
    'subdomain reply-to' => ['support@help.app.test', 'updates@app.test', 'pass'],
    'invalid sender' => ['hello@app.test', 'invalid', 'fail'],
]);

test('local transports need neither a sender nor reply-to', function (string $transport) {
    $this->preflight['config']->set([
        'mail.default' => 'local',
        'mail.mailers.local' => ['transport' => $transport],
        'mail.from.address' => null,
        'mail.reply_to.address' => null,
    ]);

    $tester = preflightCommand($this->preflight, ['--only' => 'mail', '--strict' => true, '--json' => true]);
    expect($tester->getStatusCode())->toBe(0);
    expect(preflightResults($tester)['mail.reply-to'])->toMatchArray(['level' => 'warning', 'status' => 'pass']);
})->with(['log', 'array']);

test('every selected check and the final summary are logged on the standards or default channel', function (bool $dedicated, string $group, array $changes, bool $strict, array $counts, int $exit) {
    $channel = $dedicated ? 'standards' : 'single';
    $path = $this->preflight->directory.'/result.log';
    $this->preflight['config']->set('logging.channels.'.$channel, ['driver' => 'single', 'path' => $path, 'formatter' => JsonFormatter::class]);
    $this->preflight['config']->set($changes);

    $tester = preflightCommand($this->preflight, ['--only' => $group, '--strict' => $strict, '--json' => true, '--no-stderr' => true]);
    $entries = array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES));
    $summary = array_pop($entries);

    expect($tester->getStatusCode())->toBe($exit);
    expect(array_column($entries, 'context'))->toBe(array_values(preflightResults($tester)));
    expect($summary['message'])->toBe('PREFLIGHT SUMMARY');
    expect($summary['context'])->toBe(['exit_code' => $exit, 'counts' => $counts, 'skips' => [], 'ci' => false, 'strict' => $strict, 'only' => $group]);

    foreach ($entries as $entry) {
        expect($entry['level_name'])->toBe(in_array($entry['context']['status'], ['fail', 'warn'], true) ? 'WARNING' : 'INFO');
    }

    if ($dedicated) {
        expect(file_exists($this->preflight->directory.'/default.log'))->toBeFalse();
    }
})->with([false, true])->with([
    'pass' => ['app', [], false, ['pass' => 3, 'fail' => 0, 'warn' => 0, 'skipped' => 0, 'skipped-in-ci' => 0, 'skipped-by-request' => 0], 0],
    'warning-level failure' => ['app', ['app.url' => 'http://app.test'], false, ['pass' => 2, 'fail' => 1, 'warn' => 0, 'skipped' => 0, 'skipped-in-ci' => 0, 'skipped-by-request' => 0], 0],
    'advisory under strict' => ['mail', ['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'smtp.app.test'], 'mail.from.address' => 'updates@parent.test', 'mail.reply_to.address' => 'hello@parent.test', 'mail.shared_parent_domain' => 'parent.test'], true, ['pass' => 2, 'fail' => 0, 'warn' => 1, 'skipped' => 0, 'skipped-in-ci' => 0, 'skipped-by-request' => 0], 0],
    'blocking failure' => ['app', ['app.key' => null], false, ['pass' => 2, 'fail' => 1, 'warn' => 0, 'skipped' => 0, 'skipped-in-ci' => 0, 'skipped-by-request' => 0], 1],
]);

test('stderr reports both failed and advisory checks and the summary unless disabled', function (array $options, bool $enabled) {
    $this->preflight['config']->set([
        'mail.default' => 'smtp',
        'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'smtp.app.test'],
        'mail.from.address' => 'updates@parent.test',
        'mail.shared_parent_domain' => 'parent.test',
        'mail.reply_to.address' => null,
    ]);

    $tester = preflightCommand($this->preflight, ['--only' => 'mail', '--json' => true, ...$options]);

    if ($enabled) {
        $lines = explode("\n", trim($tester->getErrorOutput()));
        expect($lines)->toHaveCount(3);
        expect($lines[0])->toStartWith('PREFLIGHT CHECK WARNING: mail.from ')->toContain('"level":"warning","status":"warn"');
        expect($lines[1])->toStartWith('PREFLIGHT CHECK FAILED: mail.reply-to ')->toContain('"level":"warning","status":"fail"');
        expect($lines[2])->toStartWith('PREFLIGHT SUMMARY ')->toContain('"exit_code":0');
        expect($tester->getErrorOutput())->not->toContain('mail.transport');
    } else {
        expect($tester->getErrorOutput())->toBe('');
    }

    expect($tester->getDisplay())->not->toContain('PREFLIGHT SUMMARY', 'PREFLIGHT CHECK');
    expect($tester->getStatusCode())->toBe(0);
})->with([
    'explicit on' => [['--stderr' => true], true],
    'explicit off' => [['--no-stderr' => true], false],
    'off takes precedence' => [['--stderr' => true, '--no-stderr' => true], false],
    'automatic' => [[], ! stream_isatty(STDOUT)],
]);

test('logging and stderr leave JSON stdout byte-identical', function (bool $stderr) {
    $tester = preflightCommand($this->preflight, ['--only' => 'mail', '--json' => true, $stderr ? '--stderr' : '--no-stderr' => true]);
    $expected = <<<'JSON'
{
    "schema": 1,
    "results": [
        {
            "check": "mail.transport",
            "level": "warning",
            "status": "pass",
            "message": "Configured mail transport packages and keys are present."
        },
        {
            "check": "mail.from",
            "level": "warning",
            "status": "pass",
            "message": "Log and array transports do not require a sender."
        },
        {
            "check": "mail.reply-to",
            "level": "warning",
            "status": "pass",
            "message": "Log and array transports do not require Reply-To."
        }
    ],
    "exit_code": 0
}
JSON;

    expect($tester->getDisplay())->toBe($expected.PHP_EOL);
    expect(file_get_contents($this->preflight->directory.'/default.log'))->toContain('PREFLIGHT CHECK PASSED: mail.transport', 'PREFLIGHT SUMMARY');
    expect($tester->getErrorOutput())->toBe($stderr ? 'PREFLIGHT SUMMARY {"exit_code":0,"counts":{"pass":3,"fail":0,"warn":0,"skipped":0,"skipped-in-ci":0,"skipped-by-request":0},"skips":[],"ci":false,"strict":false,"only":"mail"}'.PHP_EOL : '');
})->with([false, true]);

test('summary logs current CI and emergency skips with their distinct counts', function () {
    $path = $this->preflight->directory.'/result.log';
    $this->preflight['config']->set('logging.channels.single', ['driver' => 'single', 'path' => $path, 'formatter' => JsonFormatter::class]);
    putenv('PREFLIGHT_SKIP=app.url');

    $tester = preflightCommand($this->preflight, ['--only' => 'app', '--ci' => true, '--strict' => true, '--skip' => 'app.env-debug', '--json' => true, '--stderr' => true]);
    $entries = array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES));
    expect($entries)->toHaveCount(4);
    expect($entries[0]['context'])->toMatchArray(['check' => 'app.key', 'level' => 'blocking', 'status' => 'skipped-in-ci']);
    expect($entries[1]['context'])->toMatchArray(['check' => 'app.env-debug', 'status' => 'skipped-by-request', 'reason' => 'Emergency skip requested by --skip.']);
    expect($entries[1]['context'])->toHaveKeys(['time', 'commit', 'message', 'level']);
    expect($entries[2]['context'])->toMatchArray(['check' => 'app.url', 'status' => 'skipped-by-request', 'reason' => 'Emergency skip requested by PREFLIGHT_SKIP.']);
    $summary = ['exit_code' => 0, 'counts' => ['pass' => 0, 'fail' => 0, 'warn' => 0, 'skipped' => 0, 'skipped-in-ci' => 1, 'skipped-by-request' => 2], 'skips' => ['app.key', 'app.env-debug', 'app.url'], 'ci' => true, 'strict' => true, 'only' => 'app'];
    expect($entries[3]['context'])->toBe($summary);
    expect($tester->getErrorOutput())->toContain('PREFLIGHT SUMMARY '.json_encode($summary, JSON_THROW_ON_ERROR));
});

test('usage errors log an exit-two summary without changing JSON stdout', function () {
    $path = $this->preflight->directory.'/result.log';
    $this->preflight['config']->set('logging.channels.single', ['driver' => 'single', 'path' => $path, 'formatter' => JsonFormatter::class]);
    $tester = preflightCommand($this->preflight, ['--only' => 'unknown', '--json' => true, '--stderr' => true]);
    $entry = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($tester->getStatusCode())->toBe(2);
    expect($tester->getDisplay())->toBe('{"schema":1,"results":[],"exit_code":2,"error":"Unknown check group: unknown"}'.PHP_EOL);
    expect($entry['message'])->toBe('PREFLIGHT SUMMARY');
    expect($entry['context'])->toBe(['exit_code' => 2, 'counts' => ['pass' => 0, 'fail' => 0, 'warn' => 0, 'skipped' => 0, 'skipped-in-ci' => 0, 'skipped-by-request' => 0], 'skips' => [], 'ci' => false, 'strict' => false, 'only' => 'unknown', 'error' => 'Unknown check group: unknown']);
    expect($tester->getErrorOutput())->toContain('PREFLIGHT SUMMARY ', '"exit_code":2');
});

test('queue checks agree with sync until needed for every worker policy', function (string $connection, ?bool $expectedWorker, string $connectionStatus, string $workerStatus) {
    if ($connection === 'async') {
        preflightDatabase($this->preflight);
    } elseif ($connection === 'managed') {
        $this->preflight['config']->set('queue.connections.managed.driver', 'cloud');
        foreach (['db', 'redis', 'queue'] as $service) {
            $this->preflight->bind($service, function () use ($service) {
                throw new RuntimeException('Managed queues must not resolve '.$service.'.');
            });
        }
    }
    $this->preflight['config']->set('queue.default', $connection);
    if ($expectedWorker !== null) {
        $this->preflight['config']->set('preflight.queue.expect_worker', $expectedWorker);
    }

    $tester = preflightCommand($this->preflight, ['--only' => 'queue', '--strict' => true, '--json' => true]);
    $results = preflightResults($tester);
    expect($tester->getStatusCode())->toBe(0);
    expect(array_keys($results))->toBe(['queue.connection', 'queue.worker']);
    expect($results['queue.connection'])->toMatchArray(['level' => 'warning', 'status' => $connectionStatus]);
    expect($results['queue.worker'])->toMatchArray(['level' => 'warning', 'status' => $workerStatus]);
    if ($connectionStatus === 'warn') {
        expect($results['queue.connection']['message'])->toContain('QUEUE_CONNECTION=sync', 'Laravel Cloud managed queues');
    }
    if ($workerStatus === 'warn') {
        expect($results['queue.worker']['message'])->toBe($results['queue.connection']['message']);
    }
    if ($connection === 'managed') {
        expect($results['queue.worker']['message'])->toBe('Managed by the platform (Laravel Cloud managed queue); no worker to check.');
    }
})->with([
    'sync unset' => ['sync', null, 'pass', 'pass'],
    'sync false' => ['sync', false, 'pass', 'pass'],
    'sync true' => ['sync', true, 'warn', 'pass'],
    'async unset' => ['async', null, 'pass', 'pass'],
    'async false' => ['async', false, 'warn', 'warn'],
    'async true' => ['async', true, 'pass', 'pass'],
    'cloud unset' => ['managed', null, 'pass', 'pass'],
    'cloud false' => ['managed', false, 'warn', 'pass'],
    'cloud true' => ['managed', true, 'pass', 'pass'],
]);

test('Cloud markers and connection names do not override the resolved queue driver', function (string $driver) {
    $previous = getenv('LARAVEL_CLOUD');
    putenv('LARAVEL_CLOUD=1');
    $this->preflight['config']->set(['queue.default' => 'cloud', 'queue.connections.cloud.driver' => $driver]);
    if ($driver === 'database') {
        $database = preflightDatabase($this->preflight)->connection('backlog');
        $database->table('worker_jobs')->insert(['queue' => 'notifications', 'available_at' => time() - 3600, 'reserved_at' => null]);
        $this->preflight['config']->set(['queue.default' => 'cloud', 'queue.connections.cloud' => $this->preflight['config']->get('queue.connections.async')]);
    }

    try {
        $tester = preflightCommand($this->preflight, ['--only' => 'queue', '--json' => true]);
        expect(preflightResults($tester)['queue.connection']['status'])->toBe('pass');
        expect(preflightResults($tester)['queue.worker']['status'])->toBe($driver === 'sync' ? 'pass' : 'warn');
        expect(preflightResults($tester)['queue.worker']['message'])->not->toContain('Managed by the platform');
    } finally {
        $previous === false ? putenv('LARAVEL_CLOUD') : putenv('LARAVEL_CLOUD='.$previous);
    }
})->with(['sync', 'database', 'redis', 'sqs', 'unknown']);

test('database backlog checks only the configured available queue with one read', function (?int $age, int $threshold, string $status) {
    Carbon::setTestNow('2026-10-13 12:00:00');
    $now = Carbon::now()->getTimestamp();
    $database = preflightDatabase($this->preflight)->connection('backlog');
    $database->table('worker_jobs')->insert([
        ['queue' => 'elsewhere', 'available_at' => $now - 3600, 'reserved_at' => null],
        ['queue' => 'notifications', 'available_at' => $now + 60, 'reserved_at' => null],
        ['queue' => 'notifications', 'available_at' => $now - 3600, 'reserved_at' => $now - 30],
    ]);
    if ($age !== null) {
        $database->table('worker_jobs')->insert([
            ['queue' => 'notifications', 'available_at' => $now - 30, 'reserved_at' => null],
            ['queue' => 'notifications', 'available_at' => $now - $age, 'reserved_at' => null],
        ]);
    }
    $this->preflight['config']->set('preflight.queue.max_backlog_minutes', $threshold);
    $database->enableQueryLog();

    try {
        $tester = preflightCommand($this->preflight, ['--only' => 'queue', '--strict' => true, '--json' => true]);
        $result = preflightResults($tester)['queue.worker'];
        expect($tester->getStatusCode())->toBe(0);
        expect($result)->toMatchArray(['level' => 'warning', 'status' => $status]);
        expect($database->getQueryLog())->toHaveCount(1);
        if ($status === 'warn') {
            expect($result['message'])->toContain((string) $threshold, "Enable the hosting platform's managed queue (Laravel Cloud managed queues)", 'QUEUE_CONNECTION=sync');
        }
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'empty available queue' => [null, 15, 'pass'],
    'fresh backlog' => [899, 15, 'pass'],
    'at threshold' => [900, 15, 'pass'],
    'old backlog' => [901, 15, 'warn'],
    'raised threshold' => [901, 20, 'pass'],
    'lowered threshold' => [301, 5, 'warn'],
]);

test('queue evidence is unknown without exposing connection exceptions', function (string $driver, string $reason) {
    $this->preflight['config']->set([
        'queue.default' => 'async',
        'queue.connections.async.driver' => $driver,
    ]);
    $this->preflight->bind($driver === 'redis' ? 'redis' : 'db', function () {
        throw new RuntimeException('secret-must-not-leak');
    });

    $tester = preflightCommand($this->preflight, ['--only' => 'queue', '--strict' => true, '--json' => true]);
    expect($tester->getStatusCode())->toBe(0);
    expect(preflightResults($tester)['queue.worker'])->toMatchArray(['level' => 'warning', 'status' => 'warn']);
    expect(preflightResults($tester)['queue.worker']['message'])->toContain('Unknown', $reason, 'Laravel Cloud managed queues', 'QUEUE_CONNECTION=sync');
    expect($tester->getDisplay())->not->toContain('secret-must-not-leak');
})->with([
    'unsupported driver' => ['sqs', 'unsupported'],
    'database error' => ['database', 'connection'],
    'redis error' => ['redis', 'connection'],
]);

test('CI skips worker evidence before opening a queue connection', function () {
    $this->preflight['config']->set(['queue.default' => 'async', 'queue.connections.async.driver' => 'database']);
    $this->preflight->bind('db', function () {
        throw new RuntimeException('The production database must not be opened in CI.');
    });

    $tester = preflightCommand($this->preflight, ['--only' => 'queue', '--ci' => true, '--strict' => true, '--json' => true]);
    expect($tester->getStatusCode())->toBe(0);
    expect(preflightResults($tester)['queue.connection']['status'])->toBe('pass');
    expect(preflightResults($tester)['queue.worker'])->toMatchArray(['level' => 'warning', 'status' => 'skipped-in-ci']);
});

test('worker timeout remains advisory even in strict mode', function () {
    $this->preflight['config']->set(['queue.default' => 'async', 'queue.connections.async.driver' => 'database', 'preflight.timeout' => 1]);
    $this->preflight->bind('db', function () {
        while (true) {
            hrtime(true);
        }
    });

    $started = microtime(true);
    $tester = preflightCommand($this->preflight, ['--only' => 'queue', '--strict' => true, '--json' => true]);
    expect($tester->getStatusCode())->toBe(0);
    expect(preflightResults($tester)['queue.worker'])->toMatchArray(['level' => 'warning', 'status' => 'warn']);
    expect(preflightResults($tester)['queue.worker']['message'])->toContain('Unknown', 'timed out after 1s', 'Laravel Cloud managed queues', 'QUEUE_CONNECTION=sync');
    expect(microtime(true) - $started)->toBeLessThan(3);
})->skip(! function_exists('pcntl_alarm'), '2026-10-13: interrupting a busy backlog read requires real PCNTL alarms, unavailable on Windows.');

test('Redis backlog uses one atomic read and reports indeterminate ages as unknown', function (int $length, mixed $createdAt, string $status, bool $unknown) {
    $client = new class($length, $createdAt)
    {
        public int $calls = 0;

        public function __construct(private int $length, private mixed $createdAt) {}

        public function isCluster(): bool
        {
            return false;
        }

        public function command(string $method, array $parameters): array
        {
            $this->calls++;
            [, $keys, $key] = $parameters;
            expect($method)->toBe('eval');
            expect($keys)->toBe(1)->and($key)->toBe('queues:notifications');

            return [$this->length, json_encode(['createdAt' => $this->createdAt])];
        }
    };
    $redis = new class($client)
    {
        public function __construct(private object $client) {}

        public function connection(string $name): object
        {
            expect($name)->toBe('backlog');

            return $this->client;
        }
    };
    $this->preflight->instance('redis', $redis);
    $this->preflight['config']->set([
        'queue.default' => 'async',
        'queue.connections.async' => ['driver' => 'redis', 'connection' => 'backlog', 'queue' => 'notifications'],
    ]);
    Carbon::setTestNow('2026-10-13 12:00:00');

    try {
        $tester = preflightCommand($this->preflight, ['--only' => 'queue', '--strict' => true, '--json' => true]);
        $result = preflightResults($tester)['queue.worker'];
        expect($tester->getStatusCode())->toBe(0)->and($client->calls)->toBe(1);
        expect($result)->toMatchArray(['level' => 'warning', 'status' => $status]);
        expect(str_contains($result['message'], 'Unknown'))->toBe($unknown);
        if ($status === 'warn') {
            expect($result['message'])->toContain('Laravel Cloud managed queues', 'QUEUE_CONNECTION=sync');
        }
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'empty' => [0, null, 'pass', false],
    'fresh' => [2, 1791892501, 'pass', false],
    'at threshold' => [2, 1791891900, 'pass', false],
    'old' => [2, 1791891899, 'warn', false],
    'no timestamp' => [2, null, 'warn', true],
    'invalid timestamp' => [2, 'not-a-timestamp', 'warn', true],
]);

test('worker policy env is parsed as a nullable boolean', function (?string $value, ?bool $expected) {
    $autoload = array_key_first(ClassLoader::getRegisteredLoaders()).'/autoload.php';
    $process = new Process([PHP_BINARY, '-r', 'require $argv[1]; $config = require $argv[2]; echo json_encode($config["queue"]["expect_worker"]);', $autoload, dirname(__DIR__).'/config/preflight.php'], env: ['PREFLIGHT_QUEUE_EXPECT_WORKER' => $value ?? false]);
    $process->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe($expected);
})->with([
    'unset' => [null, null],
    'true' => ['true', true],
    'false' => ['false', false],
    'one' => ['1', true],
    'zero' => ['0', false],
    'invalid' => ['invalid', null],
]);

test('[rule 51] distribution config reads its environment override', function (?string $value) {
    $autoload = array_key_first(ClassLoader::getRegisteredLoaders()).'/autoload.php';
    $process = new Process([PHP_BINARY, '-r', 'require $argv[1]; $config = require $argv[2]; echo json_encode($config["ci"]["distribution"]);', $autoload, dirname(__DIR__).'/config/preflight.php'], env: ['PREFLIGHT_CI_DISTRIBUTION' => $value ?? false]);
    $process->mustRun();
    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe($value);
})->with([null, 'app', 'package']);

test('[rule 50] every Laravel default fails without blocking deployment unless strict', function (?string $fixture) {
    $extension = $fixture === null ? 'ico' : pathinfo($fixture, PATHINFO_EXTENSION);
    $name = $extension === 'png' ? 'apple-touch-icon.png' : 'favicon.'.$extension;
    $contents = $fixture === null ? '' : file_get_contents($fixture);
    $hash = hash('sha256', $contents);
    expect($hash)->toBe($fixture === null ? 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855' : pathinfo($fixture, PATHINFO_FILENAME));
    expect(CoreCheck::KNOWN_DEFAULT_HASHES)->toContain($hash);
    file_put_contents($this->preflight->root.'/public/'.$name, $contents);

    foreach ([false => 0, true => 1] as $strict => $exit) {
        $tester = preflightCommand($this->preflight, ['--only' => 'brand', '--ci' => true, '--strict' => (bool) $strict, '--json' => true]);
        expect($tester->getStatusCode())->toBe($exit);
        expect(preflightResults($tester))->toHaveCount(1);
        expect(preflightResults($tester)['brand.favicon'])->toMatchArray(['level' => 'warning', 'status' => 'fail']);
        expect(preflightResults($tester)['brand.favicon']['message'])->toContain($name, '180×180', 'every root layout');
    }
})->with([null, ...glob(dirname(__DIR__).'/fixtures/Favicons/*')]);

test('[rule 50] missing ICO fails and app-owned files pass in the full CI run', function () {
    $tester = preflightCommand($this->preflight, ['--ci' => true, '--strict' => true, '--json' => true]);
    expect($tester->getStatusCode())->toBe(0);
    expect(preflightResults($tester)['brand.favicon'])->toMatchArray(['level' => 'warning', 'status' => 'pass']);
    $fixtures = array_map(fn (string $path): string => pathinfo($path, PATHINFO_FILENAME), glob(dirname(__DIR__).'/fixtures/Favicons/*'));
    expect(CoreCheck::KNOWN_DEFAULT_HASHES)->toEqualCanonicalizing(['e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', ...$fixtures]);

    unlink($this->preflight->root.'/public/favicon.ico');
    $tester = preflightCommand($this->preflight, ['--only' => 'brand', '--strict' => true, '--json' => true]);
    expect($tester->getStatusCode())->toBe(1);
    expect(preflightResults($tester)['brand.favicon']['status'])->toBe('fail');
    expect(preflightResults($tester)['brand.favicon']['message'])->toContain('favicon.ico');
});

test('[rule 50] layout source needs an actual icon link', function (string $source, string $status) {
    file_put_contents($this->preflight->root.'/resources/views/app.blade.php', $source);
    $tester = preflightCommand($this->preflight, ['--only' => 'brand', '--strict' => true, '--json' => true]);
    expect(preflightResults($tester)['brand.favicon']['status'])->toBe($status);
    expect($tester->getStatusCode())->toBe($status === 'pass' ? 0 : 1);
})->with([
    'no link' => ['<html><head></head></html>', 'fail'],
    'touch only' => ['<link rel="apple-touch-icon" href="/apple-touch-icon.png">', 'fail'],
    'suppressor' => ['<link rel="icon" href="data:,">', 'fail'],
    'case-insensitive data' => ["<LINK HREF=' DATA:image/svg+xml,abc' REL='ICON'>", 'fail'],
    'commented HTML' => ['<!-- <link rel="icon" href="/favicon.svg"> -->', 'fail'],
    'commented Blade' => ['{{-- <link rel="icon" href="/favicon.svg"> --}}', 'fail'],
    'empty href' => ['<link rel="icon" href="">', 'fail'],
    'real' => ['<link rel="icon" href="/favicon.svg">', 'pass'],
    'unquoted uppercase' => ['<LINK HREF=/favicon.ico REL=ICON>', 'pass'],
    'shortcut icon' => ["<link href='/favicon.ico' rel='shortcut icon'>", 'pass'],
    'mixed' => ['<link rel="icon" href="data:,"><link rel="icon" href="/favicon.svg">', 'pass'],
]);

test('[rule 50] configured root views and every overridden layout are inspected', function () {
    mkdir($this->preflight->root.'/resources/views/layouts');
    file_put_contents($this->preflight->root.'/resources/views/app.blade.php', 'No icon link');
    file_put_contents($this->preflight->root.'/resources/views/layouts/root.blade.php', '<link rel="icon" href="/favicon.svg">');
    $this->preflight['config']->set('inertia.root_view', 'layouts.root');
    $tester = preflightCommand($this->preflight, ['--only' => 'brand', '--strict' => true, '--json' => true]);
    expect($tester->getStatusCode())->toBe(0);
    expect(preflightResults($tester)['brand.favicon']['status'])->toBe('pass');

    $this->preflight['config']->set('preflight.brand.layouts', ['resources/views/layouts/root.blade.php', 'resources/views/app.blade.php']);
    $tester = preflightCommand($this->preflight, ['--only' => 'brand', '--strict' => true, '--json' => true]);
    expect($tester->getStatusCode())->toBe(1);
    expect(preflightResults($tester)['brand.favicon']['status'])->toBe('fail');

    $this->preflight['config']->set('preflight.brand.layouts', ['resources/views/layouts/root.blade.php']);
    expect(preflightResults(preflightCommand($this->preflight, ['--only' => 'brand', '--json' => true]))['brand.favicon']['status'])->toBe('pass');
});

test('[rule 50] missing layouts are unknown advisories even under strict', function (array $layouts) {
    unlink($this->preflight->root.'/resources/views/app.blade.php');
    $this->preflight['config']->set('preflight.brand.layouts', $layouts);
    $tester = preflightCommand($this->preflight, ['--only' => 'brand', '--strict' => true, '--json' => true]);
    expect($tester->getStatusCode())->toBe(0);
    expect(preflightResults($tester)['brand.favicon']['status'])->toBe('warn');
    expect(preflightResults($tester)['brand.favicon']['message'])->toContain('Unknown', 'every root layout');
})->with([[[]], [['resources/views/missing.blade.php']]]);

test('[rule 50] extra configured default hashes add to the built-in set', function () {
    $path = $this->preflight->root.'/public/favicon.svg';
    $this->preflight['config']->set('preflight.brand.known_default_hashes', [hash_file('sha256', $path)]);
    $tester = preflightCommand($this->preflight, ['--only' => 'brand', '--strict' => true, '--json' => true]);
    expect($tester->getStatusCode())->toBe(1);
    expect(preflightResults($tester)['brand.favicon']['status'])->toBe('fail');

    copy(dirname(__DIR__).'/fixtures/Favicons/242f4f8f93f5fbc6c8aeec500c9bac02dcfe68daba166d484c5cdc986c88d8ee.svg', $path);
    $tester = preflightCommand($this->preflight, ['--only' => 'brand', '--strict' => true, '--json' => true]);
    expect($tester->getStatusCode())->toBe(1);
    expect(preflightResults($tester)['brand.favicon']['status'])->toBe('fail');
});

test('[rule 50] each current upstream set has byte-exact SHA-256 fixtures in the default list', function (string $repository, string $commit) {
    $files = $repository === 'laravel' ? ['favicon.ico' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'] : [
        'favicon.ico' => '4606a56e6ef3f5ec39201497f57069d5457ce9cea25227134d0ba378788e9070',
        'favicon.svg' => '242f4f8f93f5fbc6c8aeec500c9bac02dcfe68daba166d484c5cdc986c88d8ee',
        'apple-touch-icon.png' => '4001aa032ff113e1a268a9bbf1ab0fd9949439f9f54a85895956eb323aba977d',
    ];

    foreach ($files as $name => $hash) {
        $contents = $repository === 'laravel' ? '' : file_get_contents(dirname(__DIR__).'/fixtures/Favicons/'.$hash.'.'.pathinfo($name, PATHINFO_EXTENSION));
        expect(hash('sha256', $contents))->toBe($hash, 'laravel/'.$repository.'@'.$commit.' public/'.$name);
        expect(CoreCheck::KNOWN_DEFAULT_HASHES)->toContain($hash);
    }
})->with([
    'skeleton' => ['laravel', 'f4000aeb018fcbf71d4a13e3ee4b80c7e2d45be5'],
    'Vue' => ['vue-starter-kit', 'd282e817c6c2fa1bd475f7c42ea785ccfc67d0ab'],
    'React' => ['react-starter-kit', '717b8f55aefd82d25d4119eaebdc8e3a72b8d7e5'],
    'Svelte' => ['svelte-starter-kit', '76d6cf46b6cc7236121a75b9e4f1bea8809cb490'],
    'Livewire' => ['livewire-starter-kit', '0f62a26c4e4b401c1300930f47d72a497add8cce'],
]);

test('[rule 50] configured hashes compare SHA-256 only for every file', function (string $name) {
    $path = $this->preflight->root.'/public/'.$name;
    $this->preflight['config']->set('preflight.brand.known_default_hashes', [hash_file('sha1', $path)]);
    $tester = preflightCommand($this->preflight, ['--only' => 'brand', '--strict' => true, '--json' => true]);
    expect(preflightResults($tester)['brand.favicon']['status'])->toBe('pass');

    $this->preflight['config']->set('preflight.brand.known_default_hashes', [hash_file('sha256', $path)]);
    $tester = preflightCommand($this->preflight, ['--only' => 'brand', '--strict' => true, '--json' => true]);
    expect($tester->getStatusCode())->toBe(1);
    expect(preflightResults($tester)['brand.favicon']['message'])->toContain($name, 'matches a Laravel default');
})->with(['favicon.ico', 'favicon.svg', 'apple-touch-icon.png']);

test('[rule 50] malformed or missing icon files warn and fail only under strict', function (string $name, ?string $contents, string $problem) {
    $path = $this->preflight->root.'/public/'.$name;
    if ($contents === null) {
        unlink($path);
    } else {
        file_put_contents($path, $contents);
    }

    foreach ([false => 0, true => 1] as $strict => $exit) {
        $tester = preflightCommand($this->preflight, ['--only' => 'brand', '--ci' => true, '--strict' => (bool) $strict, '--json' => true]);
        expect($tester->getStatusCode())->toBe($exit);
        expect(preflightResults($tester)['brand.favicon'])->toMatchArray(['level' => 'warning', 'status' => 'fail']);
        expect(preflightResults($tester)['brand.favicon']['message'])->toContain('public/'.$name, $problem);
    }
})->with([
    'ICO header' => ['favicon.ico', 'not an ICO', 'ICO header'],
    'cursor header' => ['favicon.ico', pack('vvv', 0, 2, 2).str_repeat("\0", 32), 'ICO header'],
    'reserved header' => ['favicon.ico', pack('vvv', 1, 1, 2).str_repeat("\0", 32), 'ICO header'],
    'truncated count' => ['favicon.ico', "\0\0\1\0", 'directory'],
    'truncated directory' => ['favicon.ico', pack('vvv', 0, 1, 2).str_repeat("\0", 16), 'directory'],
    'no entries' => ['favicon.ico', pack('vvv', 0, 1, 0), '16×16 and 32×32'],
    'missing 16' => ['favicon.ico', pack('vvv', 0, 1, 1).pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, 0, 22), '16×16'],
    'missing 32' => ['favicon.ico', pack('vvv', 0, 1, 1).pack('CCCCvvVV', 16, 16, 0, 0, 1, 32, 0, 22), '32×32'],
    'rectangular entries' => ['favicon.ico', pack('vvv', 0, 1, 2).pack('CCCCvvVV', 16, 32, 0, 0, 1, 32, 0, 38).pack('CCCCvvVV', 32, 16, 0, 0, 1, 32, 0, 38), '16×16 and 32×32'],
    '256 is not 16 or 32' => ['favicon.ico', pack('vvv', 0, 1, 1).str_repeat("\0", 16), '256×256'],
    'missing PNG' => ['apple-touch-icon.png', null, 'missing'],
    'empty PNG' => ['apple-touch-icon.png', '', 'empty'],
    'invalid PNG' => ['apple-touch-icon.png', 'not a PNG', 'PNG'],
    'GIF named PNG' => ['apple-touch-icon.png', "GIF89a\xb4\0\xb4\0\x80\0\0".str_repeat("\0", 6).';', 'PNG'],
    'narrow PNG' => ['apple-touch-icon.png', preflightPng(179, 180), 'found 179×180'],
    'wide PNG' => ['apple-touch-icon.png', preflightPng(181, 180), 'found 181×180'],
    'short PNG' => ['apple-touch-icon.png', preflightPng(180, 179), 'found 180×179'],
    'tall PNG' => ['apple-touch-icon.png', preflightPng(180, 181), 'found 180×181'],
    'missing SVG' => ['favicon.svg', null, 'missing'],
    'empty SVG' => ['favicon.svg', '', 'empty'],
    'whitespace SVG' => ['favicon.svg', '   ', '<svg root'],
    'wrong SVG root' => ['favicon.svg', '<html><svg/></html>', '<svg root'],
    'commented SVG' => ['favicon.svg', '<!-- <svg/> -->', '<svg root'],
    'similar root name' => ['favicon.svg', '<svg-icon/>', '<svg root'],
]);

test('[rule 50] extra ICO sizes and XML declarations before the SVG root are allowed', function () {
    $path = $this->preflight->root.'/public/favicon.ico';
    $ico = file_get_contents($path);
    file_put_contents($path, substr_replace($ico, pack('v', 3), 4, 2).str_repeat("\0", 16));
    file_put_contents($this->preflight->root.'/public/favicon.svg', '<?xml version="1.0"?><!-- app icon --><svg xmlns="http://www.w3.org/2000/svg"/>');
    $tester = preflightCommand($this->preflight, ['--only' => 'brand', '--ci' => true, '--strict' => true, '--json' => true]);
    expect($tester->getStatusCode())->toBe(0);
    expect(preflightResults($tester)['brand.favicon']['status'])->toBe('pass');
});

test('[rule 50] the README table publishes both raw-content hashes for every default fixture', function () {
    $readme = file_get_contents(dirname(__DIR__).'/README.md');
    preg_match_all('/^\| `([^`]+)`[^|]*\|[^|]+\| `([a-f0-9]{64})` \| `([a-f0-9]{40})` \|$/m', $readme, $rows, PREG_SET_ORDER);
    expect(array_column($rows, 2))->toEqualCanonicalizing(CoreCheck::KNOWN_DEFAULT_HASHES);

    foreach ($rows as [, $name, $sha256, $sha1]) {
        $contents = $sha256 === 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
            ? '' : file_get_contents(dirname(__DIR__).'/fixtures/Favicons/'.$sha256.'.'.pathinfo($name, PATHINFO_EXTENSION));
        expect(hash('sha256', $contents))->toBe($sha256);
        expect(hash('sha1', $contents))->toBe($sha1);
    }
});

test('[rule 51] CI-only preflight reports findings but strict enforcement is opt-in', function (bool $ci, bool $strict, bool $enforce, ?string $fixture, string $status, int $exit) {
    $this->preflight['config']->set(['preflight.ci.distribution' => 'app', 'preflight.ci.enforce' => $enforce]);

    if ($fixture !== null) {
        mkdir($this->preflight->root.'/.github/workflows', 0777, true);
        copy(dirname(__DIR__).'/fixtures/CI/'.$fixture, $this->preflight->root.'/.github/workflows/tests.yml');
    }

    $tester = preflightCommand($this->preflight, ['--only' => 'ci', '--ci' => $ci, '--strict' => $strict, '--json' => true]);
    $results = preflightResults($tester);
    expect(array_keys($results))->toBe(['ci.triggers']);
    expect($results['ci.triggers'])->toMatchArray(['level' => 'warning', 'status' => $status]);
    expect($tester->getStatusCode())->toBe($exit);

    if ($status === 'fail') {
        expect($results['ci.triggers']['message'])->toContain('.github/workflows/tests.yml', '[test]', 'timeout:', 'docs/recipes/backend/ci-triggers.md', 'README');
        expect($tester->getErrorOutput())->toContain('PREFLIGHT CHECK FAILED: ci.triggers', '"status":"fail"');
        expect(file_get_contents($this->preflight->root.'/default.log'))->toContain('PREFLIGHT CHECK FAILED: ci.triggers');
        if (! $enforce) {
            expect($results['ci.triggers']['message'])->toContain('PREFLIGHT_CI_ENFORCE=true');
        }
    } elseif ($status === 'warn') {
        expect($results['ci.triggers']['message'])->toContain('Unknown');
    } elseif ($status === 'pass') {
        expect($results['ci.triggers']['message'])->toContain('Advisory docs-skip', 'required checks');
    } else {
        expect(file_get_contents($this->preflight->root.'/default.log'))->toContain('PREFLIGHT CHECK SKIPPED: ci.triggers', '"skipped":1', '"skips":["ci.triggers"]');
    }
})->with([
    [true, true, false, 'compliant.yml', 'pass', 0],
    [true, false, false, 'timeout.yml', 'fail', 0],
    [true, true, false, 'timeout.yml', 'fail', 0],
    [true, false, true, 'timeout.yml', 'fail', 0],
    [true, true, true, 'timeout.yml', 'fail', 1],
    [false, true, true, 'timeout.yml', 'skipped', 0],
    [false, false, false, null, 'skipped', 0],
    [true, true, true, null, 'warn', 0],
]);

test('[rule 51] preflight uses configured branch and distribution policies', function () {
    mkdir($this->preflight->root.'/.github/workflows', 0777, true);
    $source = str_replace(['branches: [main]', 'runs-on: ubuntu-latest'], ['branches: [trunk]', 'runs-on: windows-latest'], file_get_contents(dirname(__DIR__).'/fixtures/CI/php-matrix.yml'));
    file_put_contents($this->preflight->root.'/.github/workflows/tests.yml', $source);
    $this->preflight['config']->set(['preflight.ci.default_branches' => ['trunk'], 'preflight.ci.distribution' => 'package', 'preflight.ci.enforce' => true]);
    $options = ['--only' => 'ci', '--ci' => true, '--strict' => true, '--json' => true];
    expect(preflightCommand($this->preflight, $options)->getStatusCode())->toBe(0);
    $this->preflight['config']->set('preflight.ci.distribution', 'app');
    $tester = preflightCommand($this->preflight, $options);
    expect($tester->getStatusCode())->toBe(1);
    expect(preflightResults($tester)['ci.triggers']['message'])->toContain('php-matrix:')->not->toContain('push-branches:');
});

test('[rule 51] cache and unknown distribution suggestions never fail strict JSON preflight', function (bool $known, bool $cached, string $status) {
    mkdir($this->preflight->root.'/.github/workflows', 0777, true);
    $workflow = Yaml::parseFile(dirname(__DIR__).'/fixtures/CI/compliant.yml');

    if (! $cached) {
        $workflow['jobs']['test']['steps'] = [['run' => 'npm ci']];
    }

    if ($known) {
        file_put_contents($this->preflight->root.'/composer.json', '{"type":"project"}');
    }

    file_put_contents($this->preflight->root.'/.github/workflows/tests.yml', Yaml::dump($workflow, 10));
    $tester = preflightCommand($this->preflight, ['--only' => 'ci', '--ci' => true, '--strict' => true, '--json' => true]);
    $result = preflightResults($tester)['ci.triggers'];
    expect($tester->getStatusCode())->toBe(0);
    expect($result['status'])->toBe($status);

    if (! $cached) {
        expect($result['message'])->toContain('Advisory', 'cache:', 'Suggest');
    }

    if (! $known) {
        expect($result['message'])->toContain('Unknown', 'preflight.ci.distribution');
    }
})->with([[true, false, 'warn'], [true, true, 'pass'], [false, true, 'warn']]);

test('[rule 51] the distributed standards workflow has no failures, with macOS and cache suggestions only', function () {
    $check = new CoreCheck('ci.triggers', new Repository(['preflight' => ['ci' => ['distribution' => 'package']]]), dirname(__DIR__));
    $result = $check->run();
    expect($result->status->value)->toBe('warn');
    expect($result->message)->toContain('macOS', 'cache:', 'Suggest')->not->toContain('windows-gap:', 'os-matrix:');
});

test('[rule 51] drafts env config reaches strict preflight including invalid values', function (?string $value, string $expected, string $status, int $exit) {
    $autoload = array_key_first(ClassLoader::getRegisteredLoaders()).'/autoload.php';
    $process = new Process([PHP_BINARY, '-r', 'require $argv[1]; $config = require $argv[2]; echo json_encode($config["ci"]);', $autoload, dirname(__DIR__).'/config/preflight.php'], env: ['PREFLIGHT_CI_DRAFTS' => $value ?? false]);
    $process->mustRun();
    $ci = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($ci['drafts'])->toBe($expected);
    $this->preflight['config']->set('preflight.ci', [...$ci, 'enforce' => true, 'distribution' => 'app']);
    mkdir($this->preflight->root.'/.github/workflows', 0777, true);
    copy(dirname(__DIR__).'/fixtures/CI/draft-guard.yml', $this->preflight->root.'/.github/workflows/tests.yml');

    $previous = getenv('GITHUB_EVENT_PATH');
    putenv('GITHUB_EVENT_PATH');

    try {
        $tester = preflightCommand($this->preflight, ['--only' => 'ci', '--ci' => true, '--strict' => true, '--json' => true]);
        $result = preflightResults($tester)['ci.triggers'];
        expect($result['status'])->toBe($status);
        expect($tester->getStatusCode())->toBe($exit);

        if ($expected === 'bogus') {
            expect($result['message'])->toContain('drafts-invalid:', '"bogus"', 'draft-guard:');
            (new Filesystem)->deleteDirectory($this->preflight->root.'/.github');
            $tester = preflightCommand($this->preflight, ['--only' => 'ci', '--ci' => true, '--strict' => true, '--json' => true]);
            expect($tester->getStatusCode())->toBe(1);
            expect(preflightResults($tester)['ci.triggers']['message'])->toContain('drafts-invalid:', '"bogus"');
        }
    } finally {
        putenv($previous === false ? 'GITHUB_EVENT_PATH' : 'GITHUB_EVENT_PATH='.$previous);
    }
})->with([
    'default' => [null, 'lint', 'fail', 1],
    'full' => ['full', 'full', 'warn', 0],
    'invalid' => ['bogus', 'bogus', 'fail', 1],
]);

test('[rule 51] enforcement env is parsed as a boolean defaulting to false', function (?string $value, bool $expected) {
    $autoload = array_key_first(ClassLoader::getRegisteredLoaders()).'/autoload.php';
    $process = new Process([PHP_BINARY, '-r', 'require $argv[1]; $config = require $argv[2]; echo json_encode($config["ci"]["enforce"]);', $autoload, dirname(__DIR__).'/config/preflight.php'], env: ['PREFLIGHT_CI_ENFORCE' => $value ?? false]);
    $process->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe($expected);
})->with([
    'unset' => [null, false],
    'true' => ['true', true],
    'false' => ['false', false],
    'one' => ['1', true],
    'zero' => ['0', false],
    'yes' => ['yes', true],
    'off' => ['off', false],
    'invalid' => ['invalid', false],
]);
