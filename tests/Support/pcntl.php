<?php

declare(strict_types=1);

namespace Tey\Standards\Preflight;

/**
 * Test-only signal doubles for non-alarm command behaviour on Windows.
 * Real interruption tests are skipped; production keeps its PCNTL requirement.
 */
const SIGALRM = 14;

function function_exists(string $function): bool
{
    return in_array($function, ['pcntl_alarm', 'pcntl_async_signals'], true) || \function_exists($function);
}

function pcntl_signal_get_handler(int $signal): int
{
    return 0;
}

function pcntl_async_signals(bool $enable): bool
{
    return false;
}

function pcntl_alarm(int $seconds): int
{
    return 0;
}

function pcntl_signal(int $signal, callable|int $handler): bool
{
    return true;
}
