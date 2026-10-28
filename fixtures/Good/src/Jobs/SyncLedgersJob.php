<?php

declare(strict_types=1);

namespace FixtureGood\Jobs;

use FixtureGood\Models\Ledger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\Multitenancy\Jobs\TenantAware;

// Spatie's way: the job carries its tenant and runs inside it.
final class SyncLedgersJob implements ShouldQueue, TenantAware
{
    public function handle(): void
    {
        Ledger::query()->count();
    }
}
