<?php

declare(strict_types=1);

namespace FixtureGood\Console\Commands;

use FixtureGood\Jobs\SyncLedgersJob;
use FixtureGood\Models\Ledger;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

// Spatie's way: the command runs once per tenant (--tenant=*). It dispatches inside execute() from a block closure, which keeps the tenant.
final class ReconcileLedgersCommand extends Command
{
    use TenantAware;

    protected $signature = 'ledgers:reconcile {--tenant=*}';

    public function handle(): int
    {
        $this->line((string) Ledger::query()->count());
        $tenant = $this->laravel->make('currentTenant');
        $tenant->execute(function (): void {
            SyncLedgersJob::dispatch();
        });

        return self::SUCCESS;
    }
}
