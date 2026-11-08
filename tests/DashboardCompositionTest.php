<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Conformance;
use Tey\Standards\Enforcement\Gate;

/** A widget class extending the kit's dashboard Widget, with the given method bodies. */
function dashboardWidget(string $name, array $methods): string
{
    $body = implode("\n", array_map(fn (string $method, string $return): string => "    public function {$method}(): string\n    {\n        {$return}\n    }\n", array_keys($methods), $methods));

    return "use Tey\\Kit\\Features\\SessionUi\\Dashboard\\Widget;\n\nfinal class {$name} extends Widget\n{\n{$body}}\n";
}

/** Rule ui.dashboard-composition on a scratch app; tenantConfiguration() comes from TenantAwareTest, as in ScopedExistsTest. */
function dashboardFindings(array $widgets, array $options = []): array
{
    $base = tenantApp($widgets);
    try {
        return array_map(fn (array $v): string => $v['target'].' '.$v['check'].(($v['advisory'] ?? false) ? ' [advisory]' : ''), (new Conformance(tenantConfiguration($base, $options)))->violations('ui.dashboard-composition'));
    } finally {
        (new Filesystem)->deleteDirectory(dirname($base));
    }
}

test('ui.dashboard-composition warns on a second feed widget', function () {
    expect(dashboardFindings([
        'ActivityWidget.php' => dashboardWidget('ActivityWidget', ['kind' => "return 'feed';", 'region' => "return 'main';"]),
        'NewsWidget.php' => dashboardWidget('NewsWidget', ['kind' => "return 'feed';", 'region' => "return 'main';"]),
        'TasksWidget.php' => dashboardWidget('TasksWidget', ['kind' => "return 'task';", 'region' => "return 'main';"]),
    ]))->toBe([
        'src/ActivityWidget.php FixtureScratch\\ActivityWidget is one of 2 feed widgets: a dashboard has one feed at most [advisory]',
        'src/NewsWidget.php FixtureScratch\\NewsWidget is one of 2 feed widgets: a dashboard has one feed at most [advisory]',
    ]);
});

test('ui.dashboard-composition warns on a widget in the wrong column for its kind', function () {
    expect(dashboardFindings([
        'TasksWidget.php' => dashboardWidget('TasksWidget', ['kind' => "return 'task';", 'region' => "return 'aside';"]),
        'HealthWidget.php' => dashboardWidget('HealthWidget', ['kind' => "return 'status';", 'region' => "return 'main';"]),
        'SetupWidget.php' => dashboardWidget('SetupWidget', ['kind' => "return 'setup';", 'region' => "return 'aside';"]),
        'FeedWidget.php' => dashboardWidget('FeedWidget', ['kind' => "return 'feed';", 'region' => "return 'main';"]),
    ]))->toBe([
        "src/HealthWidget.php FixtureScratch\\HealthWidget is a 'status' widget in 'main': status widgets go in 'aside' [advisory]",
        "src/TasksWidget.php FixtureScratch\\TasksWidget is a 'task' widget in 'aside': task widgets go in 'main' [advisory]",
    ]);
});

test('ui.dashboard-composition warns on a widget without kind(), and leaves a class that is not a kit widget alone', function () {
    expect(dashboardFindings([
        'LegacyWidget.php' => dashboardWidget('LegacyWidget', ['region' => "return 'main';"]),
        'OtherWidget.php' => "final class OtherWidget extends \\App\\Widget\n{\n}\n",
    ]))->toBe([
        'src/LegacyWidget.php FixtureScratch\\LegacyWidget does not declare kind(): say what the widget is (task, feed, status or setup) [advisory]',
    ]);
});

test('ui.dashboard-composition says it cannot verify a kind() or region() that is not a literal, and flags nothing else', function () {
    expect(dashboardFindings([
        'ConfiguredWidget.php' => dashboardWidget('ConfiguredWidget', ['kind' => 'return $this->kind;', 'region' => "return 'aside';"]),
        'PlacedWidget.php' => dashboardWidget('PlacedWidget', ['kind' => "return 'task';", 'region' => "return config('dashboard.region');"]),
    ]))->toBe([
        "src/ConfiguredWidget.php can't verify FixtureScratch\\ConfiguredWidget: kind() does not return one string literal [advisory]",
        "src/PlacedWidget.php can't verify FixtureScratch\\PlacedWidget: region() does not return one string literal [advisory]",
    ]);
});

test('ui.dashboard-composition stays warn only: not an opt-in advisory, and listing it in optionalRules still warns', function () {
    expect(Gate::ADVISORY)->not->toContain('ui.dashboard-composition')
        ->and(dashboardFindings(['TasksWidget.php' => dashboardWidget('TasksWidget', ['kind' => "return 'task';", 'region' => "return 'aside';"])], ['optionalRules' => ['ui.dashboard-composition']]))
        ->each->toEndWith('[advisory]');
});
