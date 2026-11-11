<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Enforcement\Gate;
use Tey\Standards\Conformance;

/** A page ViewModel extending the kit's PageViewModel, with the given class docblock. */
function purposePage(string $name, string $doc, string $modifier = 'final'): string
{
    return "use Tey\\Kit\\Features\\Core\\ViewModels\\PageViewModel;\n\n{$doc}\n{$modifier} class {$name} extends PageViewModel\n{\n}\n";
}

/** Rule ui.page-purpose on a scratch app; tenantConfiguration() comes from TenantAwareTest, as in ScopedExistsTest. */
function purposeFindings(array $files, array $options = []): array
{
    $base = tenantApp($files);
    try {
        return array_map(fn (array $v): string => $v['target'].' '.$v['check'].(($v['advisory'] ?? false) ? ' [advisory]' : ''), (new Conformance(tenantConfiguration($base, $options)))->violations('ui.page-purpose'));
    } finally {
        (new Filesystem)->deleteDirectory(dirname($base));
    }
}

test('ui.page-purpose warns on a page ViewModel without @purpose', function () {
    expect(purposeFindings([
        'AgreementsViewModel.php' => purposePage('AgreementsViewModel', "/** The agreements list. */"),
        'BareViewModel.php' => purposePage('BareViewModel', ''),
    ]))->toBe([
        'src/AgreementsViewModel.php FixtureScratch\\AgreementsViewModel does not state its purpose: add `@purpose <who>: <main job>` to its class docblock [advisory]',
        'src/BareViewModel.php FixtureScratch\\BareViewModel does not state its purpose: add `@purpose <who>: <main job>` to its class docblock [advisory]',
    ]);
});

test('ui.page-purpose warns on an @purpose not shaped <who>: <main job>', function () {
    expect(purposeFindings([
        'NoColonViewModel.php' => purposePage('NoColonViewModel', "/**\n * @purpose watch unsigned agreements\n */"),
        'NoJobViewModel.php' => purposePage('NoJobViewModel', "/**\n * @purpose Staff:\n */"),
        'NoWhoViewModel.php' => purposePage('NoWhoViewModel', "/** @purpose : watch unsigned agreements */"),
        'InlineNoJobViewModel.php' => purposePage('InlineNoJobViewModel', '/** @purpose Staff: */'),
    ]))->toBe([
        "src/InlineNoJobViewModel.php FixtureScratch\\InlineNoJobViewModel's @purpose is not shaped `<who>: <main job>` [advisory]",
        "src/NoColonViewModel.php FixtureScratch\\NoColonViewModel's @purpose is not shaped `<who>: <main job>` [advisory]",
        "src/NoJobViewModel.php FixtureScratch\\NoJobViewModel's @purpose is not shaped `<who>: <main job>` [advisory]",
        "src/NoWhoViewModel.php FixtureScratch\\NoWhoViewModel's @purpose is not shaped `<who>: <main job>` [advisory]",
    ]);
});

test('ui.page-purpose passes a valid @purpose and skips an abstract base and a class that is not a page ViewModel', function () {
    expect(purposeFindings([
        'AgreementsViewModel.php' => purposePage('AgreementsViewModel', "/**\n * The agreements list.\n *\n * @purpose Staff: watch unsigned agreements; send one occasionally.\n */"),
        'BaseViewModel.php' => purposePage('BaseViewModel', '', 'abstract'),
        'Other.php' => "final class Other extends \\App\\PageViewModel\n{\n}\n",
        'InlineViewModel.php' => purposePage('InlineViewModel', '/** @purpose Staff: watch unsigned agreements */'),
    ]))->toBe([]);
});

test('ui.page-purpose checks a page that extends an abstract base of the app', function () {
    expect(purposeFindings([
        'BasePageViewModel.php' => purposePage('BasePageViewModel', '', 'abstract'),
        'OrdersViewModel.php' => "/** The orders list. */\nfinal class OrdersViewModel extends BasePageViewModel\n{\n}\n",
        'InvoicesViewModel.php' => "/** @purpose Finance: chase unpaid invoices. */\nfinal class InvoicesViewModel extends BasePageViewModel\n{\n}\n",
    ]))->toBe([
        'src/OrdersViewModel.php FixtureScratch\\OrdersViewModel does not state its purpose: add `@purpose <who>: <main job>` to its class docblock [advisory]',
    ]);
});

test('ui.page-purpose stays warn only: not an opt-in advisory, and listing it in optionalRules still warns', function () {
    expect(Gate::ADVISORY)->not->toContain('ui.page-purpose')
        ->and(purposeFindings(['XViewModel.php' => purposePage('XViewModel', '')], ['optionalRules' => ['ui.page-purpose']]))
        ->toHaveCount(1)->each->toEndWith('[advisory]');
});
