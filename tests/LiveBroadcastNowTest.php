<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Checks\LiveBroadcastNow;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/** Rule `live.broadcast-now` on a scratch tree: the notices are parsed, never loaded (the fixture namespace does not autoload). */
function liveApp(array $files): array
{
    $base = sys_get_temp_dir().'/standards-live-'.bin2hex(random_bytes(6));
    mkdir($base.'/src', 0777, true);
    $files += [
        'Now.php' => "use Illuminate\\Contracts\\Broadcasting\\ShouldBroadcastNow;\n\nfinal class Now implements ShouldBroadcastNow {}\n",
        'Queued.php' => "use Illuminate\\Contracts\\Broadcasting\\ShouldBroadcast;\n\nfinal class Queued implements ShouldBroadcast {}\n",
        'BaseNow.php' => "use Illuminate\\Contracts\\Broadcasting\\ShouldBroadcastNow;\n\nabstract class BaseNow implements ShouldBroadcastNow {}\n",
        'Child.php' => "final class Child extends BaseNow {}\n",
    ];
    foreach ($files as $path => $source) {
        file_put_contents($base.'/src/'.$path, "<?php\n\nnamespace LiveFixture;\n\n".$source);
    }
    $config = new Configuration(...[
        'base' => $base, 'classRoots' => ['src' => 'LiveFixture'], 'sourceRoots' => ['src'], 'uiRoots' => [], 'uiNamespaces' => [], 'hostNamespaces' => [],
        'controllerRoots' => [], 'routeRoots' => [], 'controllerSuffix' => 'Controller', 'routeDirectory' => 'routes', 'viewModelRoots' => [], 'viewModelBases' => [],
        'typedViewModel' => '', 'viewModelSuffix' => 'ViewModel', 'pageRoots' => [], 'pageScaffolds' => [], 'placements' => [], 'retired' => [], 'discoveryRoots' => [],
        'developmentNamespaces' => [], 'safeAttributes' => [], 'mediaTrait' => 'X\\MediaTrait', 'mediaInterface' => 'X\\MediaInterface',
    ]);
    $violations = (new LiveBroadcastNow($config, new Source($config)))->violations();
    (new Filesystem)->deleteDirectory($base);

    return $violations;
}

function liveViewModel(string $body): array
{
    return ['Page.php' => "use Inertia\\Inertia;\nuse Tey\\Kit\\Features\\Core\\Live\\LiveProps;\n\nfinal class Page\n{\n    public function toArray(array \$props): array\n    {\n{$body}\n    }\n}\n"];
}

test('a live prop on a ShouldBroadcastNow notice passes, however the notice is passed', function (string $body) {
    expect(liveApp(liveViewModel($body)))->toBe([]);
})->with([
    'Inertia::live, named on: new' => '        return [\'a\' => Inertia::live(fn () => 1, on: new Now)];',
    'Inertia::live, positional' => '        return [\'a\' => Inertia::live(fn () => 1, new Now)];',
    'Inertia::live, ::class' => '        return [\'a\' => Inertia::live(fn () => 1, on: Now::class)];',
    'a local variable' => '        $notice = new Now;'."\n".'        return [\'a\' => Inertia::live(fn () => 1, on: $notice)];',
    'new LiveProp' => '        return [\'a\' => new \\Inertia\\LiveProp(fn () => 1, new Now)];',
    'kit LiveProps::on, positional' => '        return LiveProps::on($props, [\'a\'], new Now, when: true);',
    'kit LiveProps::on, named' => '        return LiveProps::on($props, [\'a\'], notice: new Now);',
    'a parent implements it' => '        return [\'a\' => Inertia::live(fn () => 1, on: new Child)];',
    'a literal broadcast name is not checked' => '        return [\'a\' => Inertia::live(fn () => 1, on: \'order.shipped\')];',
]);

test('a queued notice is flagged, on each call shape', function (string $body) {
    $violations = liveApp(liveViewModel($body));
    expect($violations)->toHaveCount(1)
        ->and($violations[0]['target'])->toStartWith('src/Page.php:')
        ->and($violations[0]['check'])->toContain('LiveFixture\\Queued is not ShouldBroadcastNow');
})->with([
    'Inertia::live' => '        return [\'a\' => Inertia::live(fn () => 1, on: new Queued)];',
    'a local variable' => '        $notice = new Queued;'."\n".'        return [\'a\' => Inertia::live(fn () => 1, on: $notice)];',
    'new LiveProp' => '        return [\'a\' => new \\Inertia\\LiveProp(fn () => 1, on: new Queued)];',
    'kit LiveProps::on' => '        return LiveProps::on($props, [\'a\'], new Queued);',
    'a string class name' => '        return [\'a\' => Inertia::live(fn () => 1, on: \'LiveFixture\\\\Queued\')];',
]);

test('a notice the rule cannot read, or cannot find, is reported rather than passed', function (string $body, string $needle) {
    $violations = liveApp(liveViewModel($body));
    expect($violations)->toHaveCount(1)->and($violations[0]['check'])->toContain($needle);
})->with([
    'a parameter' => ['        return [\'a\' => Inertia::live(fn () => 1, on: $props[\'n\'])];', 'cannot read'],
    'reassigned' => ['        $notice = new Now;'."\n".'        $notice = new Queued;'."\n".'        return [\'a\' => Inertia::live(fn () => 1, on: $notice)];', 'cannot read'],
    'a class that is nowhere' => ['        return [\'a\' => Inertia::live(fn () => 1, on: new Missing)];', 'LiveFixture\\Missing could not be found'],
]);

test('other calls named live or on are not live props', function () {
    expect(liveApp(liveViewModel('        $x = Other::live(1, new Queued);'."\n".'        return LiveProps::other($props, [], new Queued);')))->toBe([]);
});
