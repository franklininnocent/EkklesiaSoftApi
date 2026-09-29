<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PasswordRecoveryChallenge extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_INVALIDATED = 'invalidated';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'tenant_id',
        'email_hmac',
        'otp_verifier',
        'reset_authorization_verifier',
        'status',
        'attempt_count',
        'max_attempts',
        'resend_count',
        'expires_at',
        'reset_authorization_expires_at',
        'verified_at',
        'consumed_at',
        'invalidated_at',
        'blocked_until',
        'last_resend_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'reset_authorization_expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'blocked_until' => 'datetime',
            'last_resend_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDecoy(): bool
    {
        return $this->user_id === null;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }
}
