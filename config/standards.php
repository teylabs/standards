<?php

declare(strict_types=1);

return [
    // A PHP file that returns the app's Tey\Standards\Configuration. The Arch
    // test suite can require the same file, so both runs check one setup.
    'configuration' => env('STANDARDS_CONFIGURATION', 'tests/Arch/configuration.php'),

    // Allowlist entries expiring within this many days are listed as expiring.
    'expiring_days' => 14,

    // The branch --changed compares against when no --base is given.
    // Null: the remote's default branch (origin/HEAD), else origin/main, else main. Set it when the trunk is found elsewhere.
    'base_ref' => env('STANDARDS_BASE_REF'),

    'telemetry' => [
        // Opt in to the first-pass metric: standards:check, standards:pattern and standards:make append to storage/standards/runs.jsonl
        // (git-ignored, hash-chained; paths, rule ids and counts, never code). Logging fails open. Read it with standards:first-pass.
        'first_pass' => filter_var(env('STANDARDS_TELEMETRY_FIRST_PASS', false), FILTER_VALIDATE_BOOLEAN),
    ],

    // Namespace and roots standards:make writes into.
    'make' => [
        'namespace' => 'App',
        'app' => 'app',
        'pages' => 'resources/js/pages',
        'routes' => 'routes/web.php',
        'tests' => 'tests/Feature',
        'stubs' => null,
    ],
];
