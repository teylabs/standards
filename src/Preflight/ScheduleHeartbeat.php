<?php

declare(strict_types=1);

namespace Tey\Standards\Preflight;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The scheduler's heartbeat for schedule.running: a one-line task, scheduled every minute, that records the time of each tick in the cache. It does
 * nothing else and touches no app data.
 */
final class ScheduleHeartbeat
{
    public const NAME = 'tey-standards:schedule-heartbeat';

    public const KEY = 'tey-standards:schedule:last-tick';

    public static function register(Schedule $schedule): void
    {
        foreach ($schedule->events() as $event) {
            if ($event->description === self::NAME) {
                return;
            }
        }
        $schedule->call(static function (): void {
            self::cache()?->forever(self::KEY, Carbon::now()->getTimestamp());
        })->everyMinute()->name(self::NAME);
    }

    /** The last recorded tick, as a timestamp, or null. */
    public static function last(): ?int
    {
        try {
            $value = self::cache()?->get(self::KEY);
        } catch (Throwable) {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /** How many tasks the app (and its packages) schedule, not counting the heartbeat. */
    public static function appTasks(): int
    {
        // A container that cannot build the schedule (no console support) has nothing scheduled.
        try {
            $schedule = Container::getInstance()->make(Schedule::class);
        } catch (Throwable) {
            return 0;
        }

        return count(array_filter($schedule->events(), fn (Event $event): bool => $event->description !== self::NAME));
    }

    private static function cache(): ?Repository
    {
        $container = Container::getInstance();

        return $container->bound('cache.store') ? $container->make('cache.store') : null;
    }
}
