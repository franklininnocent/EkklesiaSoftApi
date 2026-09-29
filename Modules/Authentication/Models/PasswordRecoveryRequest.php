<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenants\Models\Tenant;

class PasswordRecoveryRequest extends Model
{
    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELIVERY_FAILED = 'delivery_failed';

    public const CLASSIFICATION_SUPER_ADMIN = 'SUPER_ADMIN';

    public const CLASSIFICATION_EKKLESIA_USER = 'EKKLESIA_USER';

    public const CLASSIFICATION_TENANT_ADMIN = 'TENANT_ADMIN';

    public const CLASSIFICATION_TENANT_USER = 'TENANT_USER';

    public const DOMAIN_PLATFORM = 'platform';

    public const DOMAIN_TENANT = 'tenant';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'tenant_id',
        'requester_email',
        'requester_domain',
        'requester_classification',
        'intended_approver_user_id',
        'processed_by_user_id',
        'status',
        'request_ip',
        'user_agent',
        'rejection_reason',
        'failure_reason',
        'expires_at',
        'processed_at',
        'password_committed_at',
        'completed_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'processed_at' => 'datetime',
            'password_committed_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function intendedApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'intended_approver_user_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_REJECTED,
            self::STATUS_EXPIRED,
            self::STATUS_FAILED,
        ], true);
    }

    public function canApprove(): bool
    {
        return $this->isPendingApproval() && ! $this->isExpired();
    }

    public function canReject(): bool
    {
        return $this->isPendingApproval() && ! $this->isExpired();
    }

    public function canRetryDelivery(): bool
    {
        return $this->status === self::STATUS_DELIVERY_FAILED && ! $this->isExpired();
    }
}
