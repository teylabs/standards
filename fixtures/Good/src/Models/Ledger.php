<?php

declare(strict_types=1);

namespace FixtureGood\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

// A tenant-scoped model whose pruning is cross-tenant by design: prunable() bypasses the scope, so model:prune (outside any tenant) works.
final class Ledger extends Model
{
    use Prunable, UsesTenantConnection;

    public function prunable(): Builder
    {
        return static::query()->withoutGlobalScopes()->where('closed', true);
    }
}
