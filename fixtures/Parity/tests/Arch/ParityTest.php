<?php

declare(strict_types=1);

use Tey\Standards\Conformance;

// Written into a copy of fixtures/App by tests/ParityTest.php: records every rule's findings as the Pest suite sees them.
test('records conformance findings inside a Pest run', function () {
    $conformance = new Conformance(require __DIR__.'/configuration.php');
    $findings = [];

    foreach (array_keys(Conformance::TESTS) as $rule) {
        $findings[(string) $rule] = $conformance->violations((string) $rule);
    }

    file_put_contents((string) getenv('STANDARDS_PARITY_OUT'), json_encode($findings, JSON_THROW_ON_ERROR));
    expect($findings)->toBeArray();
});
