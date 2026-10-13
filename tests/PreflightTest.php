<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Contracts\Log\ContextLogProcessor as ContextProcessorContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Log\Context\ContextLogProcessor;
use Illuminate\Log\LogManager;
use Monolog\Formatter\JsonFormatter;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Tey\Standards\Preflight;
use Tey\Standards\Preflight\Check;
use Tey\Standards\Preflight\Level;
use Tey\Standards\Preflight\PreflightCommand;
use Tey\Standards\Preflight\Result;
use Tey\Standards\StandardsServiceProvider;

function preflightWorld(): Container
{
    $app = new class extends Container
    {
        public string $directory;

        public function storagePath(string $path = ''): string
        {
            return $this->directory.'/'.$path;
        }

        public function basePath(): string
        {
            return dirname(__DIR__);
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
    $config = new Repository([
        'app' => ['env' => 'production', 'debug' => false, 'key' => 'test-key', 'url' => 'https://app.test'],
        'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        'mail' => ['default' => 'log', 'mailers' => ['log' => ['transport' => 'log']], 'from' => ['address' => 'alerts@app.test'], 'reply_to' => ['address' => 'hello@app.test']],
        'queue' => ['default' => 'database'],
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

beforeEach(function () {
    $this->previousContainer = Container::getInstance();
    $this->skipEnvironment = getenv('PREFLIGHT_SKIP');
    putenv('PREFLIGHT_SKIP');
    $this->preflight = preflightWorld();
    Container::setInstance($this->preflight);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->preflight->directory);
    Container::setInstance($this->previousContainer);
    $this->skipEnvironment === false ? putenv('PREFLIGHT_SKIP') : putenv('PREFLIGHT_SKIP='.$this->skipEnvironment);
});

test('the JSON contract lists every core check with its reviewed level and exits zero', function () {
    $tester = preflightCommand($this->preflight, ['--json' => true]);
    $json = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($json))->toBe(['schema', 'results', 'exit_code']);
    expect($json['schema'])->toBe(1)->and($json['exit_code'])->toBe(0)->and($tester->getStatusCode())->toBe(0);
    $expected = ['app.key' => 'blocking', 'app.env-debug' => 'blocking', 'database.config' => 'blocking', 'mail.transport' => 'warning', 'mail.from' => 'warning', 'mail.reply-to' => 'warning', 'queue.connection' => 'warning', 'cache.store' => 'warning', 'app.url' => 'warning', 'logging.channel' => 'warning'];

    foreach ($json['results'] as $result) {
        expect(array_keys($result))->toBe(['check', 'level', 'status', 'message']);
        expect($result['status'])->toBe('pass')->and($result['level'])->toBe($expected[$result['check']])->and($result['message'])->not->toBeEmpty();
    }

    expect(array_column($json['results'], 'check'))->toBe(array_keys($expected));
});

test('the provider boots and registers a working command without autoloadable architecture dependencies', function () {
    $autoload = array_key_first(ClassLoader::getRegisteredLoaders()).'/autoload.php';
    $process = new Process([PHP_BINARY, __DIR__.'/no-dev-smoke.php', $autoload, '--hide-arch']);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    expect($process->getOutput())->toContain('PASS: provider boot and preflight without Pest or PHP-Parser');
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
    ['queue.connection', ['queue.default' => 'sync'], 'warning', 0],
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
    expect(array_unique(array_column(preflightResults($tester), 'status')))->toBe(['pass']);
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
    'pass' => ['app', [], false, ['pass' => 3, 'fail' => 0, 'warn' => 0, 'skipped-in-ci' => 0, 'skipped-by-request' => 0], 0],
    'warning-level failure' => ['app', ['app.url' => 'http://app.test'], false, ['pass' => 2, 'fail' => 1, 'warn' => 0, 'skipped-in-ci' => 0, 'skipped-by-request' => 0], 0],
    'advisory under strict' => ['mail', ['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'smtp.app.test'], 'mail.from.address' => 'updates@parent.test', 'mail.reply_to.address' => 'hello@parent.test', 'mail.shared_parent_domain' => 'parent.test'], true, ['pass' => 2, 'fail' => 0, 'warn' => 1, 'skipped-in-ci' => 0, 'skipped-by-request' => 0], 0],
    'blocking failure' => ['app', ['app.key' => null], false, ['pass' => 2, 'fail' => 1, 'warn' => 0, 'skipped-in-ci' => 0, 'skipped-by-request' => 0], 1],
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

    expect($tester->getDisplay())->toBe($expected."\n");
    expect(file_get_contents($this->preflight->directory.'/default.log'))->toContain('PREFLIGHT CHECK PASSED: mail.transport', 'PREFLIGHT SUMMARY');
    expect($tester->getErrorOutput())->toBe($stderr ? 'PREFLIGHT SUMMARY {"exit_code":0,"counts":{"pass":3,"fail":0,"warn":0,"skipped-in-ci":0,"skipped-by-request":0},"skips":[],"ci":false,"strict":false,"only":"mail"}'."\n" : '');
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
    $summary = ['exit_code' => 0, 'counts' => ['pass' => 0, 'fail' => 0, 'warn' => 0, 'skipped-in-ci' => 1, 'skipped-by-request' => 2], 'skips' => ['app.key', 'app.env-debug', 'app.url'], 'ci' => true, 'strict' => true, 'only' => 'app'];
    expect($entries[3]['context'])->toBe($summary);
    expect($tester->getErrorOutput())->toContain('PREFLIGHT SUMMARY '.json_encode($summary, JSON_THROW_ON_ERROR));
});

test('usage errors log an exit-two summary without changing JSON stdout', function () {
    $path = $this->preflight->directory.'/result.log';
    $this->preflight['config']->set('logging.channels.single', ['driver' => 'single', 'path' => $path, 'formatter' => JsonFormatter::class]);
    $tester = preflightCommand($this->preflight, ['--only' => 'unknown', '--json' => true, '--stderr' => true]);
    $entry = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($tester->getStatusCode())->toBe(2);
    expect($tester->getDisplay())->toBe('{"schema":1,"results":[],"exit_code":2,"error":"Unknown check group: unknown"}'."\n");
    expect($entry['message'])->toBe('PREFLIGHT SUMMARY');
    expect($entry['context'])->toBe(['exit_code' => 2, 'counts' => ['pass' => 0, 'fail' => 0, 'warn' => 0, 'skipped-in-ci' => 0, 'skipped-by-request' => 0], 'skips' => [], 'ci' => false, 'strict' => false, 'only' => 'unknown', 'error' => 'Unknown check group: unknown']);
    expect($tester->getErrorOutput())->toContain('PREFLIGHT SUMMARY ', '"exit_code":2');
});
