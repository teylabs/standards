<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

test('the reusable standards workflow keeps exactly one job, id standards, with no name: callers require "Standards / standards"', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__).'/.github/workflows/standards.yml');

    expect($workflow)->toBeArray()
        ->and(array_keys($workflow['jobs'] ?? []))->toBe(['standards'])
        ->and($workflow['jobs']['standards'])->toBeArray()->not->toHaveKey('name');
});
