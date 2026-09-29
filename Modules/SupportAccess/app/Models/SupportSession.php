<?php

namespace Modules\SupportAccess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;

class SupportSession extends Model
{
    use HasUuids;

    protected $table = 'support_sessions';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ENDED = 'ended';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'id',
        'support_user_id',
        'tenant_id',
        'mode',
        'reason_code',
        'reason_description',
        'ticket_ref',
        'status',
        'started_at',
        'expires_at',
        'ended_at',
        'ended_reason',
        'ip_address',
        'user_agent',
        'approval_request_id',
        'access_grant_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function supportUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'support_user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupportSessionEvent::class, 'support_session_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
