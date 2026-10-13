<?php

declare(strict_types=1);

return [
    'skip' => env('PREFLIGHT_SKIP', ''),
    'timeout' => 5,
    'mail_generic_local_parts' => ['updates', 'hello', 'noreply', 'no-reply', 'no_reply', 'notifications', 'notify', 'info', 'support', 'mail'],
];
