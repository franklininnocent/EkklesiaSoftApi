<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantEntitlementOverride extends Model
{
    public const MODE_ENABLE = 'ENABLE';

    public const MODE_DISABLE = 'DISABLE';

    public const MODE_SET_LIMIT = 'SET_LIMIT';

    public const MODE_UNLIMITED = 'UNLIMITED';

    public const MODE_SET_TIER = 'SET_TIER';

    /** @var list<string> */
    public const MODES = [
        self::MODE_ENABLE,
        self::MODE_DISABLE,
        self::MODE_SET_LIMIT,
        self::MODE_UNLIMITED,
        self::MODE_SET_TIER,
    ];

    protected $table = 'tenant_entitlement_overrides';

    protected $fillable = [
        'tenant_id',
        'feature_id',
        'mode',
        'numeric_value',
        'tier_value',
        'reason',
        'effective_from',
        'effective_until',
        'created_by',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'feature_id' => 'integer',
        'numeric_value' => 'integer',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'feature_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * Open overrides whose effective window contains "now".
     */
    public function scopeInEffect(Builder $query): Builder
    {
        $now = now();

        return $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $now));
    }
}
