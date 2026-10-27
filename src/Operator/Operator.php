<?php

declare(strict_types=1);

namespace Tey\Standards\Operator;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use LogicException;
use Throwable;

/**
 * The operator command contract (pattern background.command). A command that changes data runs its work through Operator::run():
 *
 * - a dry run unless `--apply`: the work runs and counts, and write() does nothing;
 * - idempotent by contract: the work skips what is already done (`already done`), so a re-run reports it and changes nothing;
 * - refused in production unless the command is allow-listed (`standards.operator.allow`, or `STANDARDS_OPERATOR_ALLOW`, comma-separated) or run with
 *   `--force` and its name typed back at the prompt (never in a non-interactive run);
 * - one at a time: a cache lock per command; a second run is refused while the first holds it;
 * - a summary table (processed, changed, skipped, failed, with reasons) and exit 1 when anything failed, 2 when refused.
 *
 * A read-only command uses Operator::report(): the same lock, counts and summary, and no `--apply`, `--force` or production refusal. Nothing here prints
 * an exception's message or any record data: the summary holds counts and reason labels only.
 */
final class Operator
{
    public const REFUSED = 2;

    public const LOCK_SECONDS = 3600;

    /** @param  Closure(OperatorRun): void  $work */
    public static function run(Command $command, Closure $work): int
    {
        foreach (['apply', 'force'] as $option) {
            if (! $command->getDefinition()->hasOption($option)) {
                throw new LogicException($command->getName().' runs through Operator::run but its signature has no --'.$option.' option.');
            }
        }
        $apply = (bool) $command->option('apply');
        if ($apply && self::production($command) && ! self::allowed($command)) {
            if (! $command->option('force')) {
                $command->error('Refused: '.$command->getName().' writes, and this is production. Run a dry run first (without --apply), then add --force and type the command name to confirm.');

                return self::REFUSED;
            }
            if ((bool) $command->option('no-interaction') || $command->ask('Type the command name ('.$command->getName().') to run it in production') !== $command->getName()) {
                $command->error('Refused: the confirmation did not match, so nothing was written.');

                return self::REFUSED;
            }
        }

        return self::locked($command, new OperatorRun($apply), $work, $apply ? 'applied' : 'dry run');
    }

    /** A read-only command: the lock, the counts and the summary, and nothing to refuse. @param  Closure(OperatorRun): void  $work */
    public static function report(Command $command, Closure $work): int
    {
        return self::locked($command, new OperatorRun(false), $work, 'report');
    }

    /** @param  Closure(OperatorRun): void  $work */
    private static function locked(Command $command, OperatorRun $run, Closure $work, string $mode): int
    {
        $store = $command->getLaravel()->bound('cache') ? $command->getLaravel()->make('cache')->store() : null;
        $lock = $store !== null && $store->getStore() instanceof LockProvider ? $store->getStore()->lock('standards-operator:'.$command->getName(), self::LOCK_SECONDS) : null;
        // Fail closed: without a lock store two runs could interleave their writes.
        if ($lock === null) {
            $command->error('Refused: no cache store with locks is configured, so '.$command->getName().' cannot make sure it runs once at a time.');

            return self::REFUSED;
        }
        if (! $lock->get()) {
            $command->error('Refused: '.$command->getName().' is already running.');

            return self::REFUSED;
        }
        try {
            $work($run);
        } catch (Throwable $error) {
            $run->failed('stopped by '.strtolower((string) preg_replace('/(?<!^)[A-Z]/', ' $0', (new \ReflectionClass($error))->getShortName())));
            // The exception goes to the app's handler (its log), never to the console: its message may hold record data.
            if ($command->getLaravel()->bound(ExceptionHandler::class)) {
                $command->getLaravel()->make(ExceptionHandler::class)->report($error);
            }
        } finally {
            $lock->release();
        }

        return self::summary($command, $run, $mode);
    }

    private static function summary(Command $command, OperatorRun $run, string $mode): int
    {
        $counts = $run->counts();
        $command->table(['Mode', 'Processed', match ($mode) { 'dry run' => 'Would change', 'report' => 'Matched', default => 'Changed' }, 'Skipped', 'Failed'], [[$mode, $counts['processed'], $counts['changed'], $counts['skipped'], $counts['failed']]]);
        foreach ($counts['skipped_by'] as $reason => $count) {
            $command->line('  skipped '.$count.': '.$reason);
        }
        foreach ($counts['failed_by'] as $reason => $count) {
            $command->line('  failed '.$count.': '.$reason);
        }
        if ($counts['failed'] === 0 && $counts['changed'] === 0 && $mode !== 'report') {
            $command->info('Already done: nothing to change.');
        } elseif ($mode === 'dry run') {
            $command->info('Dry run: nothing was written. Run again with --apply to write.');
        }

        return $counts['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private static function production(Command $command): bool
    {
        $app = $command->getLaravel();

        return $app instanceof Application ? $app->environment('production') : ($app->bound('env') && $app->make('env') === 'production');
    }

    private static function allowed(Command $command): bool
    {
        $app = $command->getLaravel();
        $configured = $app->bound('config') ? (array) $app->make('config')->get('standards.operator.allow', []) : [];
        $env = array_filter(array_map('trim', explode(',', (string) (getenv('STANDARDS_OPERATOR_ALLOW') ?: ''))));

        return in_array($command->getName(), [...$configured, ...$env], true);
    }
}
