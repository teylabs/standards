<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use Pest\Arch\Support\Composer;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedWithMessageException;
use Symfony\Component\Process\Process;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;
use Tey\Standards\Native;
use Tey\Standards\PageConventions;

/** Register before Pest builds its cached architecture inventory. */
foreach (ClassLoader::getRegisteredLoaders() as $loader) {
    $loader->addPsr4('FixtureGood\\', dirname(__DIR__).'/fixtures/Good/src');
    $loader->addPsr4('FixtureBad\\', dirname(__DIR__).'/fixtures/Bad/src');
}

/** Scope the package preset away from fixture and consumer namespaces. */
arch()->preset()->strict()->ignoring(array_values(array_diff(Composer::userNamespaces(), ['Tey\\Standards'])));

function fixtureConfiguration(string $kind, array $allowlist = [], bool $ciEnforce = false): Configuration
{
    $namespace = 'Fixture'.$kind;

    return new Configuration(
        base: dirname(__DIR__).'/fixtures/'.$kind,
        classRoots: ['src' => $namespace],
        sourceRoots: ['src', 'routes'],
        uiRoots: ['src/UI'],
        uiNamespaces: [$namespace.'\\UI'],
        hostNamespaces: [$namespace],
        controllerRoots: ['src/Domain/*/Http/Controllers'],
        routeRoots: ['routes'],
        controllerSuffix: 'Controller',
        routeDirectory: 'routes',
        viewModelRoots: ['src/Domain/*/ViewModels', 'src/UI'],
        viewModelBases: ['FixtureGood\\UI\\TypedViewModel'],
        typedViewModel: 'FixtureGood\\UI\\TypedViewModel',
        viewModelSuffix: 'ViewModel',
        pageRoots: ['pages'],
        pageScaffolds: ['pages'],
        placements: ['Policy' => ['src/Domain/*/Policies'], 'Capability' => ['src/Domain/*/Enums']],
        retired: [$namespace.'\\Retired' => ['src/Retired']],
        discoveryRoots: ['src/Domain', 'src/UI'],
        developmentNamespaces: ['Pest', 'FixtureGood\\UI\\SafeAttribute'],
        safeAttributes: ['FixtureGood\\UI\\SafeAttribute'],
        mediaTrait: 'FixtureGood\\UI\\MediaBehaviour',
        mediaInterface: 'FixtureGood\\UI\\MediaContract',
        allowlist: $allowlist,
        ciEnforce: $ciEnforce,
        optionalRules: ['ui plus', 'ui new-tab', 'ui dropzone', 'ui toast'],
    );
}

foreach (Conformance::TESTS as $rule => $name) {
    test($name.' rejects a seeded violation and accepts the clean control', function () use ($rule) {
        $rule = (string) $rule;
        (new Conformance(fixtureConfiguration('Good', ciEnforce: true)))->assert($rule);
        expect(fn () => (new Conformance(fixtureConfiguration('Bad', ciEnforce: true)))->assert($rule))
            ->toThrow(AssertionFailedError::class, 'violation:');
    });
}

test('the CRUDDY package catches all existing route and public-surface cases', function () {
    $violations = (new Conformance(fixtureConfiguration('Bad')))->violations('21');
    $checks = array_column($violations, 'check');

    foreach (['inline validation | InvoiceController::store', 'method | InvoiceController::pay', 'method | InvoiceController::middleware', 'mixed-invoke | InvoiceController', 'flat-tuple | /invoices/pay => [pay]', 'action-string | /old => @old', 'resource-route | resource(invoices)', 'routed-method | /invoices/reorder => reorder', 'verb-uri | /invoices/reorder'] as $check) {
        expect($checks)->toContain($check);
    }
});

test('media requires both the contract and disk measurement', function () {
    expect((new Conformance(fixtureConfiguration('Bad')))->violations('19'))->toEqualCanonicalizing([
        ['target' => 'FixtureBad\\Domain\\Billing\\Models\\Photo', 'check' => 'media model must implement HasMedia'],
        ['target' => 'src/Domain/Billing/Models/Photo.php', 'check' => 'getPath call: measure conversions through the disk'],
    ]);
});

test('an exact ratchet entry permits debt but cannot hide a second violation', function () {
    $entry = ['rule' => '21', 'target' => 'src/Domain/Billing/Http/Controllers/InvoiceController.php', 'check' => 'method | InvoiceController::pay', 'reason' => 'Fixture debt', 'date' => '2026-10-12'];

    expect(fn () => (new Conformance(fixtureConfiguration('Bad', [$entry])))->assert('21'))->toThrow(AssertionFailedError::class, 'InvoiceController::middleware');
});

test('[rule 51] an exact dated CI exception permits debt and becomes stale after a fix', function () {
    $entry = ['rule' => '51', 'target' => '.github/workflows/tests.yml', 'check' => 'test | timeout: set timeout-minutes on every job.', 'reason' => 'Fixture timeout debt', 'date' => '2026-10-13'];
    (new Conformance(fixtureConfiguration('Bad', [$entry], ciEnforce: true)))->assert('51');
    expect(fn () => (new Conformance(fixtureConfiguration('Good', [$entry], ciEnforce: true)))->assert('51'))->toThrow(AssertionFailedError::class, 'stale exception');
});

test('[rule 51] advisory architecture checks skip while findings remain available', function () {
    $preset = new Conformance(fixtureConfiguration('Bad'));
    expect($preset->violations('51'))->toBe([
        ['target' => '.github/workflows/tests.yml', 'check' => 'test | timeout: set timeout-minutes on every job.'],
    ]);
    expect(fn () => $preset->assert('51'))->toThrow(SkippedWithMessageException::class, 'Rule 51 is advisory until PREFLIGHT_CI_ENFORCE=true; run `php artisan standards:preflight --ci --only=ci` to see findings');
});

test('[rule 51] Configuration threads drafts through architecture enforcement', function () {
    $base = sys_get_temp_dir().'/conformance-drafts-'.bin2hex(random_bytes(6));
    mkdir($base.'/.github/workflows', 0777, true);
    copy(dirname(__DIR__).'/fixtures/CI/draft-guard.yml', $base.'/.github/workflows/tests.yml');
    $previous = getenv('GITHUB_EVENT_PATH');
    putenv('GITHUB_EVENT_PATH');

    try {
        $arguments = array_replace(get_object_vars(fixtureConfiguration('Good', ciEnforce: true)), ['base' => $base, 'ciDistribution' => 'app']);
        $lint = new Conformance(new Configuration(...$arguments));
        expect(fn () => $lint->assert('51'))->toThrow(AssertionFailedError::class, 'draft-guard:');
        $full = new Conformance(new Configuration(...array_replace($arguments, ['ciDrafts' => 'full'])));
        $full->assert('51');
        expect($full->violations('51'))->toBe([]);
        $invalid = new Conformance(new Configuration(...array_replace($arguments, ['ciDrafts' => 'bogus'])));
        expect(fn () => $invalid->assert('51'))->toThrow(AssertionFailedError::class, 'drafts-invalid: drafts must be lint or full; got "bogus".');
    } finally {
        putenv($previous === false ? 'GITHUB_EVENT_PATH' : 'GITHUB_EVENT_PATH='.$previous);
        (new Filesystem)->deleteDirectory($base);
    }
});

test('fixed and deleted ratchet entries fail as stale, including native presets', function (string $rule, string $target, string $check) {
    $entry = compact('rule', 'target', 'check') + ['reason' => 'Fixture debt', 'date' => '2026-10-12'];
    expect(fn () => (new Conformance(fixtureConfiguration('Good', [$entry])))->assert($rule))
        ->toThrow(AssertionFailedError::class, 'stale exception');
})->with([
    ['22', 'src/UI/Link.php', 'depends on FixtureGood\\Retired\\Thing'],
    ['22', 'src/UI/Deleted.php', 'depends on FixtureGood\\Retired\\Thing'],
    ['preset security', 'FixtureGood\\UI\\Link', "Expecting 'src/UI/Link.php' not to use 'md5'."],
]);

test('the ratchet permits an exact seeded failure and forces its removal after a fix', function () {
    $entry = ['rule' => '29', 'target' => 'src/Domain/Billing/Authentication.php', 'check' => 'Fortify::authenticateUsing is forbidden', 'reason' => 'Fixture debt', 'date' => '2026-10-12'];
    (new Conformance(fixtureConfiguration('Bad', [$entry])))->assert('29');
    expect(fn () => (new Conformance(fixtureConfiguration('Good', [$entry])))->assert('29'))->toThrow(AssertionFailedError::class, 'stale exception');
});

test('native preset debt is allowed only while the same failure still exists', function () {
    $entry = ['rule' => 'preset security', 'target' => 'FixtureBad\\Domain\\Billing\\Tokens', 'check' => "Expecting 'md5' not to be used on 'FixtureBad\\Domain\\Billing\\Tokens'.", 'reason' => 'Fixture debt', 'date' => '2026-10-12'];
    (new Conformance(fixtureConfiguration('Bad', [$entry])))->assert('preset security');
    expect(fn () => (new Conformance(fixtureConfiguration('Good', [$entry])))->assert('preset security'))->toThrow(AssertionFailedError::class, 'stale exception');
});

test('native diagnostics preserve the exact Pest 4 wording used by ratchets', function (string $method, array $arguments, string $description) {
    $class = 'FixtureGood\\UI\\Link';
    $path = str_replace('/', DIRECTORY_SEPARATOR, 'fixtures/Good/src/UI/Link.php');
    expect(Native::failures(expect($class)->$method(...$arguments), $class))->toBe([
        "Expecting '{$path}' {$description}.",
    ]);
})->with([
    'method list' => ['toHaveMethod', [['handle', 'resolve']], "to have method 'handle', 'resolve'"],
    'base' => ['toExtend', ['FixtureGood\\UI\\TypedViewModel'], "to extend 'FixtureGood\\UI\\TypedViewModel'"],
    'suffix' => ['toHaveSuffix', ['ViewModel'], "to have suffix 'ViewModel'"],
    'attribute' => ['toHaveAttribute', ['Attribute'], "to have attribute 'Attribute'"],
]);

test('rule 20 reads shared scaffold allowances without exempting other page paths', function () {
    $original = fixtureConfiguration('Good');
    $directory = 'PageFixture'.bin2hex(random_bytes(8));
    $base = $original->base.'/'.$directory;
    $filesystem = new Filesystem;

    try {
        foreach (['pages/auth/Page.vue', 'pages/settings/Page.vue', 'layouts/app/Layout.vue'] as $path) {
            $filesystem->ensureDirectoryExists(dirname($base.'/resources/js/'.$path));
            file_put_contents($base.'/resources/js/'.$path, '<template />');
        }

        file_put_contents($base.'/composer.json', json_encode(['extra' => ['tey-standards' => [
            'pageScaffolds' => ['resources', 'js', 'pages', 'layouts', 'auth', 'settings', 'app'],
        ]]], JSON_THROW_ON_ERROR));
        $configuration = new Configuration(...array_replace(get_object_vars($original), [
            'pageRoots' => [$directory.'/resources/js/pages', $directory.'/resources/js/layouts'],
            'pageScaffolds' => PageConventions::scaffolds($base),
        ]));
        (new Conformance($configuration))->assert('20');

        $filesystem->ensureDirectoryExists($base.'/resources/js/pages/unlisted');
        file_put_contents($base.'/resources/js/pages/unlisted/Page.vue', '<template />');
        file_put_contents($base.'/resources/js/pages/auth/auth.vue', '<template />');
        $filesystem->ensureDirectoryExists($base.'/resources/js/pages/9Bad');
        file_put_contents($base.'/resources/js/pages/9Bad/Page.vue', '<template />');
        $paths = array_column((new Conformance($configuration))->violations('20'), 'target');
        expect($paths)->toEqualCanonicalizing([
            $directory.'/resources/js/pages/unlisted/Page.vue',
            $directory.'/resources/js/pages/auth/auth.vue',
            $directory.'/resources/js/pages/9Bad/Page.vue',
        ]);
    } finally {
        $filesystem->deleteDirectory($base);
    }
});

test('ViewModel placement checks suffix, base and directory independently', function () {
    $violations = (new Conformance(fixtureConfiguration('Bad')))->violations('20');
    expect($violations)->toContain(
        ['target' => 'FixtureBad\\Domain\\Billing\\ViewModels\\UntypedName', 'check' => 'ViewModel needs configured suffix'],
        ['target' => 'FixtureBad\\Domain\\Billing\\WrongPlaceViewModel', 'check' => 'ViewModel outside configured roots'],
        ['target' => 'FixtureBad\\Domain\\Billing\\ViewModels\\InvoiceViewModel', 'check' => 'ViewModel must extend typed base'],
        ['target' => 'pages/invoice/Page.vue', 'check' => 'page path must use PascalCase except configured scaffolds'],
    );
});

test('a safe attribute is not an exemption for runtime calls to that class', function () {
    (new Conformance(fixtureConfiguration('Good')))->assert('no-dev');
    expect((new Conformance(fixtureConfiguration('Bad')))->violations('no-dev'))->toContain(
        ['target' => 'src/UI/AttributeRuntime.php', 'check' => 'depends on FixtureGood\\UI\\SafeAttribute'],
    );
});

test('malformed dates and unknown rules cannot weaken the ratchet', function (array $entry) {
    expect(fn () => new Conformance(fixtureConfiguration('Good', [$entry])))->toThrow(InvalidArgumentException::class);
})->with([
    [['rule' => '29', 'target' => 'a', 'check' => 'b', 'reason' => 'why', 'date' => '2026-02-30']],
    [['rule' => 'unknown', 'target' => 'a', 'check' => 'b', 'reason' => 'why', 'date' => '2026-10-12']],
    [['rule' => '29', 'target' => 'a', 'check' => 'b', 'reason' => '', 'date' => '2026-10-12']],
]);

test('duplicate exceptions are rejected', function () {
    $entry = ['rule' => '29', 'target' => 'a', 'check' => 'b', 'reason' => 'why', 'date' => '2026-10-12'];
    expect(fn () => new Conformance(fixtureConfiguration('Good', [$entry, $entry])))->toThrow(InvalidArgumentException::class, 'Duplicate');
});

test('architecture checks explain missing optional dependencies', function (string $prefix) {
    $code = <<<'PHP'
    $loader = require $argv[1];
    $loader->unregister();
    spl_autoload_register(static function (string $class) use ($loader): void {
        if (! str_starts_with($class, $GLOBALS['argv'][3])) {
            $loader->loadClass($class);
        }
    });
    try {
        new Tey\Standards\Conformance(unserialize(base64_decode($argv[2])));
        exit(1);
    } catch (LogicException $error) {
        echo $error->getMessage();
    }
    PHP;
    $autoload = array_key_first(ClassLoader::getRegisteredLoaders()).'/autoload.php';
    $configuration = base64_encode(serialize(fixtureConfiguration('Good')));
    $process = new Process([PHP_BINARY, '-r', $code, $autoload, $configuration, $prefix]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    expect($process->getOutput())->toContain('Architecture checks require', 'pestphp/pest ^4.7 || ^5.0', 'pestphp/pest-plugin-arch ^4.0 || ^5.0', 'nikic/php-parser ^5.0', 'require-dev');
})->with(['Pest\\', 'Pest\\Arch\\', 'PhpParser\\']);

test('architecture checks accept Pest 4.7+ and 5 and refuse unsupported versions clearly', function (?string $version, bool $supported) {
    $assert = fn () => Conformance::assertSupportedPest($version);

    $supported
        ? expect($assert)->not->toThrow(LogicException::class)
        : expect($assert)->toThrow(LogicException::class, 'support Pest 4 (4.7+) and Pest 5; Pest '.$version.' is installed. The deploy preflight is unaffected.');
})->with([
    'Pest 4 minimum' => ['4.7.0', true],
    'Pest 4 tag' => ['v4.8.0', true],
    'not installed' => [null, true],
    'Pest 5 minimum' => ['5.0.0', true],
    'Pest 5 tag' => ['v5.3.2', true],
    'older Pest 4' => ['4.6.9', false],
    'Pest 3' => ['3.8.0', false],
    'Pest 6' => ['6.0.0', false],
]);

test('MCP declarations can use a configured manifest without loading application classes', function () {
    $base = dirname(__DIR__).'/fixtures/McpRun'.bin2hex(random_bytes(6));
    $files = new Filesystem;
    $files->copyDirectory(dirname(__DIR__).'/fixtures/Good', $base);
    try {
        $files->ensureDirectoryExists($base.'/config');
        rename($base.'/docs/mcp-capabilities.json', $base.'/config/capabilities.json');
        $configuration = new Configuration(...array_replace(get_object_vars(fixtureConfiguration('Good')), [
            'base' => $base, 'mcpManifest' => 'config/capabilities.json', 'classRoots' => ['absent' => 'Absent'],
        ]));
        (new Conformance($configuration))->assert('55');
    } finally {
        $files->deleteDirectory($base);
    }
});
