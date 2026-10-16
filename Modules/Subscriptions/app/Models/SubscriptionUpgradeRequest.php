<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;

class SubscriptionUpgradeRequest extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_INFO_REQUESTED = 'INFO_REQUESTED';

    /** Requests still waiting on Ekklesia or on the church. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_INFO_REQUESTED];

    protected $table = 'subscription_upgrade_requests';

    protected $fillable = [
        'tenant_id',
        'requested_by',
        'current_plan_id',
        'requested_plan_id',
        'requested_billing_interval',
        'feature_code',
        'message',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'resulting_subscription_id',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'requested_by' => 'integer',
        'current_plan_id' => 'integer',
        'requested_plan_id' => 'integer',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'resulting_subscription_id' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function currentPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'current_plan_id')->withTrashed();
    }

    public function requestedPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'requested_plan_id')->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
