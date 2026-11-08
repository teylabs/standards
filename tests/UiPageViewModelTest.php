<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Conformance;
use Tey\Standards\Enforcement\Gate;

/** A class on the given kit base (or an app class), with an optional body. */
function pageVmClass(string $name, string $base, string $body = ''): string
{
    return "use Tey\\Kit\\Features\\Core\\ViewModels\\PageViewModel;\nuse Tey\\Kit\\Features\\Core\\ViewModels\\ViewModel;\n\nfinal class {$name} extends {$base}\n{\n{$body}}\n";
}

/** A controller whose action renders the given props expression as the page. */
function pageVmController(string $props, string $before = ''): string
{
    return "use Inertia\\Inertia;\n\nfinal class OrdersController\n{\n    public function index(): mixed\n    {\n{$before}        return Inertia::render('Orders/Page', {$props});\n    }\n}\n";
}

/** Rule ui.page-view-model on a scratch app; tenantApp() and tenantConfiguration() come from TenantAwareTest. */
function pageVmFindings(array $files): array
{
    $base = tenantApp($files);
    try {
        return array_map(fn (array $v): string => $v['target'].' '.strtok($v['check'], ' '), (new Conformance(tenantConfiguration($base)))->violations('ui.page-view-model'));
    } finally {
        (new Filesystem)->deleteDirectory(dirname($base));
    }
}

test('ui.page-view-model passes a page on PageViewModel', function () {
    expect(pageVmFindings([
        'OrdersViewModel.php' => pageVmClass('OrdersViewModel', 'PageViewModel'),
        'OrdersController.php' => pageVmController('new OrdersViewModel()'),
    ]))->toBe([]);
});

test('ui.page-view-model flags a page on the plain ViewModel, rendered directly, through a variable or returned by an action', function () {
    expect(pageVmFindings([
        'OrdersViewModel.php' => pageVmClass('OrdersViewModel', 'ViewModel'),
        'OrdersController.php' => pageVmController('new OrdersViewModel()'),
    ]))->toBe(['src/OrdersController.php FixtureScratch\\OrdersViewModel'])
        ->and(pageVmFindings([
            'OrdersViewModel.php' => pageVmClass('OrdersViewModel', 'ViewModel'),
            'OrdersController.php' => pageVmController('$page', "        \$page = new OrdersViewModel();\n"),
        ]))->toBe(['src/OrdersController.php FixtureScratch\\OrdersViewModel'])
        ->and(pageVmFindings([
            'OrdersViewModel.php' => pageVmClass('OrdersViewModel', 'ViewModel'),
            'ShowController.php' => "final class ShowController\n{\n    public function __invoke(): OrdersViewModel\n    {\n        return new OrdersViewModel();\n    }\n}\n",
        ]))->toBe(['src/ShowController.php FixtureScratch\\OrdersViewModel']);
});

test('ui.page-view-model passes a page on an app base that extends PageViewModel, and flags one on an app base over the plain ViewModel', function () {
    expect(pageVmFindings([
        'AppPageViewModel.php' => str_replace('final class', 'abstract class', pageVmClass('AppPageViewModel', 'PageViewModel')),
        'OrdersViewModel.php' => pageVmClass('OrdersViewModel', 'AppPageViewModel'),
        'OrdersController.php' => pageVmController('new OrdersViewModel()'),
    ]))->toBe([])
        ->and(pageVmFindings([
            'AppViewModel.php' => str_replace('final class', 'abstract class', pageVmClass('AppViewModel', 'ViewModel')),
            'OrdersViewModel.php' => pageVmClass('OrdersViewModel', 'AppViewModel'),
            'OrdersController.php' => pageVmController('new OrdersViewModel()'),
        ]))->toBe(['src/OrdersController.php FixtureScratch\\OrdersViewModel']);
});

test('ui.page-view-model passes a page that sets $standalone = true, on itself or an app base', function () {
    expect(pageVmFindings([
        'TermsViewModel.php' => pageVmClass('TermsViewModel', 'ViewModel', "    protected bool \$standalone = true;\n"),
        'OrdersController.php' => pageVmController('new TermsViewModel()'),
    ]))->toBe([])
        ->and(pageVmFindings([
            'PublicViewModel.php' => str_replace('final class', 'abstract class', pageVmClass('PublicViewModel', 'ViewModel', "    protected bool \$standalone = true;\n")),
            'TermsViewModel.php' => pageVmClass('TermsViewModel', 'PublicViewModel'),
            'OrdersController.php' => pageVmController('new TermsViewModel()'),
        ]))->toBe([]);
});

test('ui.page-view-model passes a widget and a child ViewModel that are never rendered as the page', function () {
    expect(pageVmFindings([
        'TotalsViewModel.php' => pageVmClass('TotalsViewModel', 'ViewModel'),
        'OrdersViewModel.php' => pageVmClass('OrdersViewModel', 'PageViewModel', "    public function totals(): TotalsViewModel\n    {\n        return new TotalsViewModel();\n    }\n"),
        'OrdersController.php' => pageVmController('new OrdersViewModel()'),
    ]))->toBe([]);
});

test('ui.page-view-model ignores a render mention inside a comment or docblock', function () {
    expect(pageVmFindings([
        'TotalsViewModel.php' => "use Tey\\Kit\\Features\\Core\\ViewModels\\ViewModel;\n\n/** Never handed straight to `Inertia::render('Orders/Page', new TotalsViewModel())`: a child. */\nfinal class TotalsViewModel extends ViewModel\n{\n}\n",
        'OrdersController.php' => "use Inertia\\Inertia;\n\nfinal class OrdersController\n{\n    public function index(): mixed\n    {\n        // return Inertia::render('Orders/Page', new TotalsViewModel());\n        # inertia('Orders/Page', new TotalsViewModel());\n        return null;\n    }\n}\n",
    ]))->toBe([]);
});

test('ui.page-view-model is enforced: not an opt-in advisory', function () {
    expect(Gate::ADVISORY)->not->toContain('ui.page-view-model')
        ->and(Gate::OPTIONAL)->not->toContain('ui.page-view-model');
});
