<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable-once-published commercial terms and entitlements of a plan.
 */
class PlanVersion extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_SCHEDULED = 'SCHEDULED';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RETIRED = 'RETIRED';

    public const INTERVAL_MONTHLY = 'MONTHLY';

    public const INTERVAL_ANNUAL = 'ANNUAL';

    public const INTERVAL_CUSTOM = 'CUSTOM';

    public const INTERVAL_NONE = 'NONE';

    /** @var list<string> */
    public const INTERVALS = [self::INTERVAL_MONTHLY, self::INTERVAL_ANNUAL, self::INTERVAL_CUSTOM, self::INTERVAL_NONE];

    protected $table = 'plan_versions';

    protected $fillable = [
        'plan_id',
        'version_number',
        'status',
        'currency_code',
        'monthly_price',
        'annual_price',
        'setup_fee',
        'tax_inclusive',
        'tax_rate_percent',
        'tax_label',
        'trial_days',
        'billing_intervals',
        'effective_from',
        'published_at',
        'published_by',
        'retired_at',
        'retired_by',
        'change_notes',
        'content_hash',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'plan_id' => 'integer',
        'version_number' => 'integer',
        'monthly_price' => 'decimal:2',
        'annual_price' => 'decimal:2',
        'setup_fee' => 'decimal:2',
        'tax_inclusive' => 'boolean',
        'tax_rate_percent' => 'decimal:2',
        'trial_days' => 'integer',
        'billing_intervals' => 'array',
        'effective_from' => 'datetime',
        'published_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    /** Fields that may change after a version leaves DRAFT (lifecycle bookkeeping only). */
    private const LIFECYCLE_FIELDS = [
        'status', 'effective_from', 'published_at', 'published_by', 'retired_at', 'retired_by',
        'content_hash', 'updated_by', 'updated_at', 'change_notes',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getOriginal('status') === self::STATUS_DRAFT) {
                return;
            }
            $illegal = array_diff(array_keys($version->getDirty()), self::LIFECYCLE_FIELDS);
            if ($illegal !== []) {
                throw new \LogicException('Published plan versions are immutable; create a new draft version instead.');
            }
            if ($version->isDirty('status') && $version->status === self::STATUS_DRAFT && $version->getOriginal('status') !== self::STATUS_SCHEDULED) {
                throw new \LogicException('Only scheduled versions can return to draft.');
            }
        });

        static::deleting(function (self $version): void {
            if ($version->status !== self::STATUS_DRAFT) {
                throw new \LogicException('Only draft plan versions can be deleted.');
            }
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id')->withTrashed();
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class, 'plan_version_id');
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
