<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Checks\GuardsDeclared;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

function guardsConfiguration(string $base): Configuration
{
    return new Configuration(
        base: $base,
        classRoots: [], sourceRoots: [], uiRoots: [], uiNamespaces: [], hostNamespaces: [],
        controllerRoots: [], routeRoots: [], controllerSuffix: 'Controller', routeDirectory: 'routes',
        viewModelRoots: [], viewModelBases: [], typedViewModel: 'ViewModel', viewModelSuffix: 'ViewModel',
        pageRoots: [], pageScaffolds: [], placements: [], retired: [], discoveryRoots: [],
        developmentNamespaces: [], safeAttributes: [], mediaTrait: 'Media', mediaInterface: 'HasMedia',
    );
}

beforeEach(function () {
    $this->guardsBase = sys_get_temp_dir().'/standards-guards-'.bin2hex(random_bytes(6));
    (new Filesystem)->copyDirectory(dirname(__DIR__).'/fixtures/Good', $this->guardsBase);
    $this->guardsConfig = guardsConfiguration($this->guardsBase);
    $this->guards = fn (): array => (new GuardsDeclared($this->guardsConfig, new Source($this->guardsConfig)))->violations();
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->guardsBase);
});

test('a test comparing the route table or the page set with a stored copy is flagged', function (string $code) {
    file_put_contents($this->guardsBase.'/tests/RoutesTest.php', '<?php '.$code);
    $violations = ($this->guards)();
    expect($violations)->toHaveCount(1)
        ->and($violations[0]['target'])->toBe('tests/RoutesTest.php')
        ->and($violations[0]['check'])->toContain('stored copy');
})->with([
    'route table snapshot' => 'test("routes", function () { $routes = []; foreach (Illuminate\Support\Facades\Route::getRoutes() as $r) { $routes[] = $r->uri(); } expect($routes)->toBe(json_decode(file_get_contents(__DIR__."/Fixtures/routes.json"), true)); });',
    'page set snapshot' => 'test("pages", function () { expect(glob(base_path("resources/js/pages/*/Page.vue")))->toBe(require base_path("tests/Fixtures/pages.php")); });',
]);

test('a written-out list that picks pages out is flagged, with its line', function (string $code) {
    file_put_contents($this->guardsBase.'/tests/PagesTest.php', "<?php\n".$code);
    $violations = ($this->guards)();
    expect($violations)->toHaveCount(1)
        ->and($violations[0]['target'])->toBe('tests/PagesTest.php:2')
        ->and($violations[0]['check'])->toContain('written-out list');
})->with([
    'regex of modal pages' => '$modal = preg_match(\'#/(Settings/People/(Invite|ChangeRole|Transfer)|Enquiries/Create)/Page\.vue$#\', $path) === 1;',
    'flat regex of pages' => '$skip = preg_match(\'#pages/(Login|Register|Welcome)\.vue#\', $path);',
    'in_array of page names' => '$exempt = in_array($component, [\'Auth/Login\', \'Auth/Register\', \'Settings/People/Invite\'], true);',
]);

test('fixtures and lists that are not about routes or pages, or name fewer than three, pass', function (string $code) {
    file_put_contents($this->guardsBase.'/tests/OtherTest.php', "<?php\n".$code);
    expect(($this->guards)())->toBe([]);
})->with([
    'a data fixture' => 'test("x", function () { expect(json_decode(file_get_contents(__DIR__."/Fixtures/Scenario/anonymous.json"), true))->toBeArray(); });',
    'a route fixture never compared with the table' => 'test("x", function () { expect(json_decode(file_get_contents(__DIR__."/Fixtures/routes-v0.2.1.json"), true))->toBeArray(); });',
    'a regex not about pages' => '$ok = preg_match(\'#^(alpha|beta|gamma)$#\', $slug);',
    'two pages' => '$modal = preg_match(\'#/(Invite|ChangeRole)/Page\.vue$#\', $path);',
    'a declaration read from the page' => '$modal = preg_match(\'#<template>\s*<Modal[\s>]#\', $source) === 1;',
    'in_array of non-page strings' => '$ok = in_array($m, [\'GET\', \'HEAD\', \'POST\'], true);',
]);

test('a comment naming a retired fixture, and first-class callables, are not guards', function () {
    file_put_contents($this->guardsBase.'/tests/RouteRulesTest.php', '<?php /** Replaces tests/Fixtures/routes.json, retired. */ test("rules", function () { $all = Illuminate\Support\Facades\Route::getRoutes(); $f = in_array(...); expect($all)->not->toBeEmpty(); });');
    expect(($this->guards)())->toBe([]);
});
