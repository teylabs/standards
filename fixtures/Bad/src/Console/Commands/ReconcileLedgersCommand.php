<?php

declare(strict_types=1);

namespace FixtureBad\Console\Commands;

use FixtureBad\Jobs\SyncLedgersJob;
use FixtureBad\Models\Ledger;
use Illuminate\Console\Command;

// Seeded: a command that touches a tenant-scoped model without TenantAware, and dispatches from an arrow fn inside execute(), which loses the tenant.
final class ReconcileLedgersCommand extends Command
{
    protected $signature = 'ledgers:reconcile';

    public function handle(): int
    {
        $this->line((string) Ledger::query()->count());
        $tenant = $this->laravel->make('currentTenant');
        $tenant->execute(fn () => SyncLedgersJob::dispatch());

        return self::SUCCESS;
    }
}
