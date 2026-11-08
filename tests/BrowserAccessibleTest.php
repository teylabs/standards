<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Conformance;
use Tey\Standards\Enforcement\Gate;

/** A scratch app with the given test files under tests/. tenantConfiguration() comes from TenantAwareTest, as in ScopedExistsTest. */
function browserAccessibleApp(array $tests): string
{
    $base = sys_get_temp_dir().'/standards-a11y-'.bin2hex(random_bytes(6)).'/Scratch';
    mkdir($base.'/src', 0777, true);
    file_put_contents($base.'/composer.json', '{}');
    foreach ($tests as $path => $source) {
        is_dir(dirname($base.'/tests/'.$path)) || mkdir(dirname($base.'/tests/'.$path), 0777, true);
        file_put_contents($base.'/tests/'.$path, "<?php\n\n".$source);
    }

    return $base;
}

test('browser.accessible flags a test file that visits a page without an axe check, and passes one with either check', function () {
    $base = browserAccessibleApp([
        'Browser/DashboardTest.php' => "test('dashboard', function () {\n    \$page = visit('/dashboard');\n    \$page->assertSee('Welcome');\n});\n",
        'Browser/SettingsTest.php' => "test('settings', function () {\n    \$page = visit('/settings');\n    assertAccessible(\$page, 'the settings page');\n});\n",
        'Browser/LegacyTest.php' => "it('loads', function () {\n    visit('/')->assertNoAccessibilityIssues();\n});\n",
        'Browser/CommentedTest.php' => "test('x', function () {\n    \$page = visit('/x');\n    // assertAccessible(\$page, 'x');\n});\n",
        'Feature/VisitorTest.php' => "test('a method named visit is not a page visit', function () {\n    \$tracker->visit('/');\n    Tracker::visit('/');\n    // visit('/');\n});\n",
        'fixtures/Browser/FixtureTest.php' => "visit('/');\n",
    ]);

    try {
        $findings = (new Conformance(tenantConfiguration($base)))->violations('browser.accessible');
        expect(array_column($findings, 'target'))->toBe(['tests/Browser/CommentedTest.php', 'tests/Browser/DashboardTest.php'])
            ->and($findings[0]['check'])->toBe('a browser test visits a page without checking its accessibility')
            ->and($findings[0]['hint'])->toContain('assertAccessible(')
            ->and(Gate::ADVISORY)->toContain('browser.accessible');
    } finally {
        (new Filesystem)->deleteDirectory(dirname($base));
    }
});

test('browser.accessible is quiet in an app without tests', function () {
    $base = browserAccessibleApp([]);

    try {
        expect((new Conformance(tenantConfiguration($base)))->violations('browser.accessible'))->toBe([]);
    } finally {
        (new Filesystem)->deleteDirectory(dirname($base));
    }
});
