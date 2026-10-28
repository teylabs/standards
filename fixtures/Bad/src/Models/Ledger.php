<?php

declare(strict_types=1);

namespace FixtureBad\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

// Seeded: a tenant-scoped model that model:prune prunes outside any tenant (prunable() keeps the tenant scope).
final class Ledger extends Model
{
    use Prunable, UsesTenantConnection;

    public function prunable(): Builder
    {
        return static::query()->where('closed', true);
    }
}
