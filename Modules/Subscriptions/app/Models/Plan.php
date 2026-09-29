<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Tenants\Models\SubscriptionPlan;

/**
 * Catalog view of subscription_plans. Extends the existing Tenants model so legacy
 * callers keep working while the catalog gains versions and entitlements.
 *
 * Commercial terms (price, trial, entitlements) live on plan versions, not here.
 */
class Plan extends SubscriptionPlan
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_ARCHIVED = 'ARCHIVED';

    public const PRICING_FIXED = 'FIXED';

    public const PRICING_CUSTOM = 'CUSTOM';

    public const PRICING_FREE = 'FREE';

    /** @var list<string> */
    public const PRICING_TYPES = [self::PRICING_FIXED, self::PRICING_CUSTOM, self::PRICING_FREE];

    protected $fillable = [
        'key',
        'code',
        'slug',
        'name',
        'description',
        'short_description',
        'pricing_type',
        'status',
        'is_public',
        'is_featured',
        'is_assignable',
        'is_legacy',
        'badge_label',
        'metadata',
        'price',
        'max_users',
        'max_storage_mb',
        'features',
        'display_order',
        'active',
        'is_default',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'max_users' => 'integer',
        'max_storage_mb' => 'integer',
        'features' => 'array',
        'metadata' => 'array',
        'display_order' => 'integer',
        'active' => 'boolean',
        'is_default' => 'boolean',
        'is_public' => 'boolean',
        'is_featured' => 'boolean',
        'is_assignable' => 'boolean',
        'is_legacy' => 'boolean',
        'archived_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class, 'plan_id')->orderByDesc('version_number');
    }

    public function activeVersion(): HasOne
    {
        return $this->hasOne(PlanVersion::class, 'plan_id')->where('status', PlanVersion::STATUS_ACTIVE);
    }

    public function scheduledVersion(): HasOne
    {
        return $this->hasOne(PlanVersion::class, 'plan_id')->where('status', PlanVersion::STATUS_SCHEDULED);
    }

    public function scopeCatalog(Builder $query): Builder
    {
        return $query->whereNotNull('code');
    }

    public function scopePubliclyListed(Builder $query): Builder
    {
        return $query->catalog()
            ->where('status', self::STATUS_ACTIVE)
            ->where('is_public', true)
            ->where('is_legacy', false);
    }

    public function scopeAssignable(Builder $query): Builder
    {
        return $query->catalog()
            ->where('status', self::STATUS_ACTIVE)
            ->where('is_assignable', true);
    }

    public function isCustomPriced(): bool
    {
        return $this->pricing_type === self::PRICING_CUSTOM;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }
}
