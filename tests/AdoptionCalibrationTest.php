<?php

declare(strict_types=1);

use Tey\Standards\Adoption\App;
use Tey\Standards\Adoption\Facts;
use Tey\Standards\Adoption\Inventory;
use Tey\Standards\Adoption\Lists;
use Tey\Standards\Adoption\Scanner;
use Tey\Standards\Configuration;

/*
 * Fixes from calibrating standards:adoption against legacy Laravel apps. Each fixture tree under fixtures/Adoption/calibration is made up.
 */

function calibrationBase(string $case): string
{
    return dirname(__DIR__).'/fixtures/Adoption/calibration/'.$case;
}

function calibrationConfiguration(string $base, array $overrides = []): Configuration
{
    return new Configuration(...array_replace(get_object_vars(App::bare($base)), ['classRoots' => ['app' => 'App'], 'sourceRoots' => ['app']], $overrides));
}

/**
 * A one-surface-per-signal tey/kit inventory: each `$surfaces` entry is id => handRolled signals, applying everywhere.
 *
 * @param  array<string, list<array<string, mixed>>>  $surfaces
 * @param  list<array<string, mixed>>  $facts
 */
function calibrationInventory(array $surfaces, array $facts = []): Inventory
{
    $data = ['format' => 1, 'package' => ['composer' => 'tey/kit', 'alias' => '@/components/Kit'], 'facts' => $facts, 'nonSurfaces' => [], 'surfaces' => []];
    foreach ($surfaces as $id => $signals) {
        $data['surfaces'][] = ['id' => $id, 'layer' => 'ui', 'group' => 'layout', 'label' => $id, 'feature' => 'core', 'applies' => true, 'uses' => ['imports' => ['@/components/Kit:Thing']], 'handRolled' => $signals, 'docs' => 'docs/x.md', 'status' => 'stable'];
    }

    return Inventory::fromArray($data, 'calibration.json');
}

/** @return array<string, mixed> */
function calibrationReport(string $case, Inventory $inventory, ?Configuration $config = null): array
{
    $base = calibrationBase($case);
    $config ??= calibrationConfiguration($base);

    return (new Scanner(new App($base, $config), [$inventory], $config, new DateTimeImmutable('2026-10-29')))->report();
}

/** @return array<string, list<string>> surface id => evidence files */
function calibrationEvidence(array $report): array
{
    return array_column(array_map(fn (array $row): array => [$row['id'], array_column($row['signals'], 'file')], $report['rows']), 1, 0);
}

test('path globs match page-path segments case-insensitively: pages/ matches Pages/, settings/ matches Settings/; basename globs keep their case', function () {
    $report = calibrationReport('page-case', calibrationInventory([
        'auth.screens' => [['kind' => 'file-name', 'globs' => ['resources/js/pages/Auth/**/*.vue']]],
        'account.pages' => [['kind' => 'file-name', 'globs' => ['resources/js/pages/settings/*.vue']]],
        'billing.pages' => [['kind' => 'file-name', 'globs' => ['resources/js/pages/Billing/*.vue']]],
        'named.lower' => [['kind' => 'file-name', 'globs' => ['login.vue']]],
    ]));

    expect(calibrationEvidence($report))->toBe([
        // Positive: the Jetstream/Breeze capital-P layout reads like kit's lowercase one.
        'auth.screens' => ['resources/js/Pages/Auth/Login.vue'],
        'account.pages' => ['resources/js/Pages/Settings/Profile.vue'],
        // Negative: no Billing folder, and Components/Auth/Login.vue is not under pages/.
        'billing.pages' => [],
        // Negative: a basename glob is a component name and stays case-sensitive.
        'named.lower' => [],
    ])
        ->and(App::pathGlob('resources/js/pages/**/Login.vue', 'resources/js/Pages/Auth/Login.vue'))->toBeTrue()
        ->and(App::pathGlob('resources/js/pages/**/Login.vue', 'resources/js/components/Login.vue'))->toBeFalse()
        ->and(App::glob('*Button', 'button'))->toBeFalse();
});
