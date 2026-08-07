<?php

namespace Modules\Tenants\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Modules\Tenants\Support\TenantContext;

/**
 * Shared tenant stamp + scope for product models.
 * Creates use TenantContext::effectiveTenantId() (support-session aware in Phase 1).
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
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
        return $query->where('tenant_id', $tenantId);
    }
}
