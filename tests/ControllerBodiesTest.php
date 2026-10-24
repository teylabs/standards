<?php

declare(strict_types=1);

use Tey\Standards\Checks\ControllerBodies;
use Tey\Standards\Configuration;
use Tey\Standards\Ratchet;
use Tey\Standards\Source;

function bodyConfiguration(string $kind, array $options = []): Configuration
{
    $namespace = 'Body'.$kind;

    $arguments = get_object_vars(new Configuration(
        base: dirname(__DIR__).'/fixtures/ControllerBodies/'.$kind,
        classRoots: ['src' => $namespace],
        sourceRoots: ['src', 'routes'],
        uiRoots: ['src/UI'],
        uiNamespaces: [$namespace.'\\UI'],
        hostNamespaces: [$namespace],
        controllerRoots: ['src/Http/Controllers'],
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

    ));

    return new Configuration(...array_replace($arguments, $options));
}

function bodyViolations(string $kind, array $options = []): array
{
    $configuration = bodyConfiguration($kind, $options);

    return (new ControllerBodies($configuration, new Source($configuration)))->violations();
}

test('rule 21 accepts delegation, response helpers and unrelated service methods', function () {
    expect(bodyViolations('Good'))->toBe([]);
});

test('rule 21 reports each body concern separately, including private helper bodies', function () {
    $path = 'src/Http/Controllers/PostController.php';
    expect(bodyViolations('Bad'))->toEqualCanonicalizing([
        ['target' => $path, 'check' => 'inline validation | PostController::store'],
        ['target' => $path, 'check' => 'response negotiation | PostController::store'],
        ['target' => $path, 'check' => 'direct persistence | PostController::store'],
        ['target' => $path, 'check' => 'multiple operations | PostController::store'],
        ['target' => $path, 'check' => 'non-response helper | PostController::persist'],
        ['target' => $path, 'check' => 'inline validation | PostController::persist'],
        ['target' => $path, 'check' => 'direct persistence | PostController::persist'],
        ['target' => $path, 'check' => 'non-response helper | PostController::formatResponse'],
        ['target' => $path, 'check' => 'direct persistence | PostController::formatResponse'],
    ]);
});

test('rule 21 body findings use the existing exact ratchet and reject stale entries', function () {
    $violations = bodyViolations('Bad');
    $entries = array_map(fn (array $violation): array => $violation + ['rule' => '21', 'reason' => 'Fixture debt', 'date' => '2026-10-24'], $violations);
    $ratchet = new Ratchet($entries);
    expect($ratchet->failures('21', $violations))->toBe([]);
    expect($ratchet->failures('21', bodyViolations('Good')))->toHaveCount(count($entries));
    array_pop($entries);
    expect((new Ratchet($entries))->failures('21', $violations))->toHaveCount(1);
});

test('helper name configuration does not suppress validation or persistence checks', function () {
    $checks = array_column(bodyViolations('Bad', ['controllerResponseHelperPattern' => '/^(persist|formatResponse)$/']), 'check');
    expect($checks)->not->toContain('non-response helper | PostController::persist', 'non-response helper | PostController::formatResponse');
    expect($checks)->toContain('inline validation | PostController::persist', 'direct persistence | PostController::formatResponse');
});

test('invalid helper patterns fail clearly', function () {
    expect(fn () => bodyViolations('Good', ['controllerResponseHelperPattern' => '/[invalid/']))->toThrow(InvalidArgumentException::class, 'valid regular expression');
});

/** Each row isolates a distinct AST receiver or call shape. */
test('rule 21 detects each inline validation form independently', function (string $method) {
    expect(array_column(bodyViolations('Cases'), 'check'))->toContain('inline validation | ValidationController::'.$method);
})->with(['facade', 'globalHelper', 'request', 'controller', 'requestHelper', 'closure', 'variable']);

test('rule 21 detects each request negotiation form independently', function (string $method) {
    expect(array_column(bodyViolations('Cases'), 'check'))->toContain('response negotiation | NegotiationController::'.$method);
})->with(['wants', 'expects', 'ajax', 'accepts']);

/** Static, typed, fluent, constructed, property and nullsafe writes have different receivers. */
test('rule 21 detects identifiable writes independently', function (string $method) {
    expect(array_column(bodyViolations('Cases'), 'check'))->toContain('direct persistence | PersistenceController::'.$method);
})->with(['createModel', 'updateModel', 'saveModel', 'deleteModel', 'forceDeleteModel', 'database', 'builder', 'fluent', 'local', 'property', 'nullable']);

/** Static, injected, promoted, invokable, constructed and container-resolved operations differ. */
test('rule 21 recognises operation invocation shapes', function (string $controller, string $method) {
    expect(array_column(bodyViolations('Cases'), 'check'))->toContain('multiple operations | '.$controller.'::'.$method);
})->with([
    ['StaticController', 'store'],
    ['TypedController', 'store'],
    ['PropertyController', 'store'],
    ['InvokableController', '__invoke'],
    ['LocalController', 'store'],
    ['ContainerController', 'store'],
]);

test('rule 21 operation vocabulary is configurable with namespace boundaries', function () {
    $defaults = array_column(bodyViolations('Cases'), 'check');
    expect($defaults)->not->toContain('multiple operations | NamespacesController::store', 'multiple operations | SuffixesController::store', 'multiple operations | BoundaryController::store');
    $namespaces = array_column(bodyViolations('Cases', [
        'controllerOperationNamespaces' => ['BodyCases\Workflow', 'BodyCases\Reporting'],
        'controllerOperationSuffixes' => [],
    ]), 'check');
    expect($namespaces)->toContain('multiple operations | NamespacesController::store');
    expect($namespaces)->not->toContain('multiple operations | StaticController::store');
    $suffixes = array_column(bodyViolations('Cases', [
        'controllerOperationNamespaces' => [],
        'controllerOperationSuffixes' => ['Task', 'Lookup'],
    ]), 'check');
    expect($suffixes)->toContain('multiple operations | SuffixesController::store');
    expect($suffixes)->not->toContain('multiple operations | StaticController::store');
});

test('rule 21 does not count descriptor Data classes as operations', function () {
    $checks = array_column(bodyViolations('Cases'), 'check');
    expect($checks)->not->toContain('multiple operations | DescriptorController::show');
    expect($checks)->toContain('multiple operations | SuffixOnlyController::store');
    $configured = array_column(bodyViolations('Cases', ['controllerNonOperationNamespaces' => []]), 'check');
    expect($configured)->toContain('multiple operations | DescriptorController::show');
});

test('rule 21 response helper shape rejects work hidden behind response construction', function () {
    $checks = array_column(bodyViolations('Cases'), 'check');
    foreach (['persist', 'sideEffect', 'disguised'] as $method) {
        expect($checks)->toContain('non-response helper | HelpersController::'.$method);
    }
    foreach (['page', 'json', 'toast'] as $method) {
        expect($checks)->not->toContain('non-response helper | HelpersController::'.$method);
    }
});

test('rule 21 body checks also scan controllers outside the mapped controller roots', function () {
    expect(array_column(bodyViolations('Cases', ['controllerRoots' => []]), 'check'))->toContain('inline validation | UnmappedController::store');
});
