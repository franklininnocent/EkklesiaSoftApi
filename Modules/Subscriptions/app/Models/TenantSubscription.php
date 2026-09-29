<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenants\Models\Tenant;

/**
 * Which plan version a tenant is pinned to. Not tenant-scoped via BelongsToTenant:
 * every read goes through services that take the tenant id from the authenticated
 * context (tenant APIs) or a platform-authorised route parameter (admin APIs).
 */
class TenantSubscription extends Model
{
    public const RECORD_PENDING = 'PENDING';

    public const RECORD_CURRENT = 'CURRENT';

    public const RECORD_SUPERSEDED = 'SUPERSEDED';

    public const RECORD_CANCELLED = 'CANCELLED';

    public const SOURCE_ASSIGNMENT = 'ASSIGNMENT';

    public const SOURCE_DEFAULT = 'DEFAULT';

    public const SOURCE_BACKFILL = 'BACKFILL';

    public const SOURCE_SCHEDULED = 'SCHEDULED';

    public const SOURCE_UPGRADE_REQUEST = 'UPGRADE_REQUEST';

    public const SOURCE_VERSION_MIGRATION = 'VERSION_MIGRATION';

    protected $table = 'tenant_subscriptions';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'plan_version_id',
        'record_status',
        'billing_interval',
        'currency_code',
        'contracted_price',
        'custom_limits',
        'starts_at',
        'scheduled_for',
        'source',
        'assigned_by',
        'reason',
        'superseded_at',
        'superseded_by_id',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'plan_id' => 'integer',
        'plan_version_id' => 'integer',
        'contracted_price' => 'decimal:2',
        'custom_limits' => 'array',
        'starts_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id')->withTrashed();
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class, 'plan_version_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('record_status', self::RECORD_CURRENT);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
