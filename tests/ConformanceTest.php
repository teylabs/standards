<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Pest\Arch\Support\Composer;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;
use Tey\Standards\Configuration;
use Tey\Standards\Conformance;

/** Register before Pest builds its cached architecture inventory. */
foreach (ClassLoader::getRegisteredLoaders() as $loader) {
    $loader->addPsr4('FixtureGood\\', dirname(__DIR__).'/fixtures/Good/src');
    $loader->addPsr4('FixtureBad\\', dirname(__DIR__).'/fixtures/Bad/src');
}

/** Scope the package preset away from fixture and consumer namespaces. */
arch()->preset()->strict()->ignoring(array_values(array_diff(Composer::userNamespaces(), ['Tey\\Standards'])));

function fixtureConfiguration(string $kind, array $allowlist = []): Configuration
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
    );
}

foreach (Conformance::TESTS as $rule => $name) {
    test($name.' rejects a seeded violation and accepts the clean control', function () use ($rule) {
        $rule = (string) $rule;
        (new Conformance(fixtureConfiguration('Good')))->assert($rule);
        expect(fn () => (new Conformance(fixtureConfiguration('Bad')))->assert($rule))
            ->toThrow(AssertionFailedError::class, 'violation:');
    });
}

test('the CRUDDY package catches all existing route and public-surface cases', function () {
    $violations = (new Conformance(fixtureConfiguration('Bad')))->violations('21');
    $checks = array_column($violations, 'check');

    foreach (['method | InvoiceController::pay', 'method | InvoiceController::middleware', 'mixed-invoke | InvoiceController', 'flat-tuple | /invoices/pay => [pay]', 'action-string | /old => @old', 'resource-route | resource(invoices)', 'routed-method | /invoices/reorder => reorder', 'verb-uri | /invoices/reorder'] as $check) {
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
    expect($process->getOutput())->toContain('Architecture checks require', 'pestphp/pest ^4.7', 'pestphp/pest-plugin-arch ^4.0', 'nikic/php-parser ^5.0', 'require-dev');
})->with(['Pest\\', 'Pest\\Arch\\', 'PhpParser\\']);
