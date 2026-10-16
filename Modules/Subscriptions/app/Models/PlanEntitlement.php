<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanEntitlement extends Model
{
    protected $table = 'plan_entitlements';

    protected $fillable = [
        'plan_version_id',
        'feature_id',
        'is_enabled',
        'numeric_value',
        'tier_value',
        'config',
    ];

    protected $casts = [
        'plan_version_id' => 'integer',
        'feature_id' => 'integer',
        'is_enabled' => 'boolean',
        'numeric_value' => 'integer',
        'config' => 'array',
    ];

    protected static function booted(): void
    {
        $guard = static function (self $entitlement): void {
            $status = PlanVersion::query()->whereKey($entitlement->plan_version_id)->value('status');
            if ($status !== null && $status !== PlanVersion::STATUS_DRAFT) {
                throw new \LogicException('Entitlements of a published plan version are immutable.');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class, 'plan_version_id');
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'feature_id');
    }
}
