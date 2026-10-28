<?php

declare(strict_types=1);

namespace FixtureBad\Jobs;

use FixtureBad\Models\Ledger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\Multitenancy\Jobs\NotTenantAware;

// Seeded: a NotTenantAware job that touches a tenant-scoped model runs outside any tenant and finds nothing.
final class SyncLedgersJob implements NotTenantAware, ShouldQueue
{
    public function handle(): void
    {
        Ledger::query()->count();
    }
}
