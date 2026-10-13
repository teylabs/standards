<?php

declare(strict_types=1);

namespace Tey\Standards\Preflight;

use RuntimeException;
use Throwable;

final class Runner
{
    public function run(Check $check, int $seconds, bool $ci): Result
    {
        if (! $ci && $check->name() === 'ci.triggers') {
            return new Result(Status::Skipped, 'Skipped outside CI: workflow checks run only with --ci.');
        }

        $handler = pcntl_signal_get_handler(SIGALRM);
        $async = pcntl_async_signals(true);
        $alarm = pcntl_alarm(0);
        $timedOut = false;
        $started = hrtime(true);

        try {
            pcntl_signal(SIGALRM, function () use (&$timedOut): never {
                $timedOut = true;
                throw new RuntimeException('Preflight timeout.');
            });
            pcntl_alarm($seconds);
            $result = $ci && $check->needsSecrets()
                ? new Result(Status::SkippedInCi, 'Skipped in CI: requires production secrets.')
                : $check->run();

            return $timedOut || (hrtime(true) - $started) / 1e9 >= $seconds
                ? $this->failure($check, "Check timed out after {$seconds}s; fix the check or its bounded timeout.")
                : $result;
        } catch (Throwable) {
            return $this->failure($check, $timedOut
                ? "Check timed out after {$seconds}s; fix the check or its bounded timeout."
                : 'Check threw an exception; fix the check configuration (exception details withheld to protect secrets).');
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $handler);
            pcntl_async_signals($async);

            if ($alarm > 0) {
                pcntl_alarm($alarm);
            }
        }
    }

    private function failure(Check $check, string $message): Result
    {
        return $check->name() === 'queue.worker'
            ? Result::warn('Unknown: '.$message." Verify the hosting platform's managed queue (Laravel Cloud managed queues) is enabled for this connection, or set QUEUE_CONNECTION=sync deliberately for future jobs; review any existing backlog.")
            : Result::fail($message);
    }
}
