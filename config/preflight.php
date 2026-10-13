<?php

declare(strict_types=1);

$expectWorker = env('PREFLIGHT_QUEUE_EXPECT_WORKER');

return [
    'skip' => env('PREFLIGHT_SKIP', ''),
    'timeout' => 5,
    'mail_generic_local_parts' => ['updates', 'hello', 'noreply', 'no-reply', 'no_reply', 'notifications', 'notify', 'info', 'support', 'mail'],
    'queue' => [
        // Sync until needed, then a scale-to-zero managed queue (Laravel Cloud managed queues).
        // A worker means whatever drains the queue, normally the managed queue, not an always-on process.
        'expect_worker' => $expectWorker === null ? null : filter_var($expectWorker, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        'max_backlog_minutes' => (int) env('PREFLIGHT_QUEUE_MAX_BACKLOG_MINUTES', 15),
    ],
];
