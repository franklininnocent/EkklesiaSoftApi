<?php

namespace Modules\Donations\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::creating(function ($model): void {
            if (empty($model->tenant_id) && Auth::check()) {
                $model->tenant_id = Auth::user()->tenant_id;
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
