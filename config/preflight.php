<?php

declare(strict_types=1);

$expectWorker = env('PREFLIGHT_QUEUE_EXPECT_WORKER');

return [
    'skip' => env('PREFLIGHT_SKIP', ''),
    'timeout' => 5,
    'mail_generic_local_parts' => ['updates', 'hello', 'noreply', 'no-reply', 'no_reply', 'notifications', 'notify', 'info', 'support', 'mail'],
    'ci' => [
        'enforce' => filter_var(env('PREFLIGHT_CI_ENFORCE', false), FILTER_VALIDATE_BOOLEAN),
        'default_branches' => ['main'],
        // app: our Linux deployment; package: anything others install or run. Null detects composer type, then artisan.
        'distribution' => env('PREFLIGHT_CI_DISTRIBUTION'),
        // lint: skip full suites on drafts; full: public repos may test drafts fully.
        'drafts' => env('PREFLIGHT_CI_DRAFTS', 'lint'),
    ],
    'brand' => [
        // Empty uses inertia.root_view (or app); override with every root Blade layout, relative to base_path().
        'layouts' => [],
        // Extra SHA-256 defaults to reject, in addition to CoreCheck::KNOWN_DEFAULT_HASHES.
        'known_default_hashes' => [],
    ],
    'vite' => [
        // The directory under public/ that holds manifest.json; match Vite::useBuildDirectory().
        'build_directory' => 'build',
    ],
    'sessions' => [
        // True when an OAuth consent screen runs without laravel/passport (for example an MCP consent screen).
        'consent' => false,
    ],
    'deploy' => [
        // The committed deploy script(s), relative to base_path(), when they live somewhere deploy.migrate does not look (a copy of the Laravel Cloud deploy commands, say).
        'script' => array_values(array_filter(explode(',', (string) env('PREFLIGHT_DEPLOY_SCRIPT', '')))),
    ],
    'queue' => [
        // Sync until needed, then a scale-to-zero managed queue (Laravel Cloud managed queues).
        // A worker means whatever drains the queue, normally the managed queue, not an always-on process.
        'expect_worker' => $expectWorker === null ? null : filter_var($expectWorker, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        'max_backlog_minutes' => (int) env('PREFLIGHT_QUEUE_MAX_BACKLOG_MINUTES', 15),
    ],
];
