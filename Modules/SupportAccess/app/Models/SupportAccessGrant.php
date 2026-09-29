<?php

namespace Modules\SupportAccess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\SupportSessionMode;

class SupportAccessGrant extends Model
{
    use HasUuids;

    protected $table = 'support_access_grants';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    public const MODE_ANY = 'any';

    protected $fillable = [
        'id',
        'tenant_id',
        'granted_by_user_id',
        'allowed_mode',
        'starts_at',
        'ends_at',
        'status',
        'note',
        'max_sessions',
        'sessions_used',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_sessions' => 'integer',
            'sessions_used' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function coversMode(SupportSessionMode|string $mode): bool
    {
        $value = $mode instanceof SupportSessionMode ? $mode->value : $mode;

        return $this->allowed_mode === self::MODE_ANY || $this->allowed_mode === $value;
    }

    public function isCurrentlyActive(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $now = now();

        return $this->starts_at !== null
            && $this->ends_at !== null
            && $this->starts_at->lte($now)
            && $this->ends_at->gt($now)
            && ($this->max_sessions === null || $this->sessions_used < $this->max_sessions);
    }
}
