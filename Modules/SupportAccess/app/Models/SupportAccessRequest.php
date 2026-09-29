<?php

namespace Modules\SupportAccess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;

class SupportAccessRequest extends Model
{
    use HasUuids;

    protected $table = 'support_access_requests';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'id',
        'requester_user_id',
        'tenant_id',
        'mode',
        'reason_code',
        'reason_description',
        'ticket_ref',
        'status',
        'requested_at',
        'expires_at',
        'decided_by_user_id',
        'decided_at',
        'decision_note',
        'consumed_session_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isPendingUsable(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function isApprovedUsable(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
