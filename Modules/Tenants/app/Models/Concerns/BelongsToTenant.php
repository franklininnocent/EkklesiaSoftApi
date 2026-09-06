<?php

namespace Modules\Tenants\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Modules\Tenants\Models\Scopes\TenantScope;
use Modules\Tenants\Support\TenantContext;

/**
 * Shared tenant stamp, global read scope, and manual scope helpers for product models.
 *
 * Creates use TenantContext::effectiveTenantId() (support-session aware).
 * Reads are filtered by TenantScope unless explicitly bypassed.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model): void {
            if (empty($model->tenant_id)) {
                $effective = app(TenantContext::class)->effectiveTenantId();
                if ($effective !== null && $effective > 0) {
                    $model->tenant_id = $effective;
                }
            }

            if (empty($model->created_by) && Auth::check()) {
                $model->created_by = Auth::id();
            }

            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });

        static::updating(function ($model): void {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where($this->qualifyColumn(TenantScope::COLUMN), $tenantId);
    }

    /**
     * Start a query without the automatic tenant global scope.
     */
    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope(TenantScope::class);
    }

    /**
     * Execute a callback with the tenant global scope disabled.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runWithoutTenantScope(callable $callback): mixed
    {
        return TenantScope::runWithout($callback);
    }
}
