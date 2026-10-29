<?php

declare(strict_types=1);

use Tey\Standards\Conformance;

test('preset security leaves seeder and factory roots out, keeping app code flagged; securityExemptRoots adapts it', function () {
    $targets = fn (array $roots): array => array_values(array_unique(array_column((new Conformance(versionedConfiguration(dirname(__DIR__).'/fixtures/Bad', ['classRoots' => ['src' => 'FixtureBad'], 'securityExemptRoots' => $roots])))->violations('preset security'), 'target')));
    $seeded = ['FixtureBad\\Database\\Seeders\\DemoSeeder', 'FixtureBad\\Database\\Factories\\PostFactory'];

    expect($targets(['src/Database/Seeders', 'src/Database/Factories']))->toContain('FixtureBad\\Domain\\Billing\\Tokens')->not->toContain(...$seeded)
        ->and($targets([]))->toContain('FixtureBad\\Domain\\Billing\\Tokens', ...$seeded);
});

test('the default exempt roots are Laravel\'s seeder and factory folders', function () {
    expect(versionedConfiguration('/app')->securityExemptRoots)->toBe(['database/seeders', 'database/factories']);
});

test('preset security accepts unserialize with a literal allowed_classes list or false, and nothing looser', function () {
    $configuration = versionedConfiguration(dirname(__DIR__).'/fixtures/Unserialize', ['classRoots' => ['src' => 'FixtureUnserialize']]);
    $targets = array_values(array_unique(array_column(array_filter((new Conformance($configuration))->violations('preset security'), fn (array $v): bool => str_contains($v['check'], "'unserialize'")), 'target')));

    expect($targets)->toEqualCanonicalizing(array_map(fn (string $class): string => 'FixtureUnserialize\\Unsafe\\'.$class,
        ['NoOptions', 'AllowsAll', 'VariableOptions', 'VariableClasses', 'OtherKeyOnly', 'SpreadOptions', 'MixedCalls']));
});
