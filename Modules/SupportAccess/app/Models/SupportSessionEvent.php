<?php

namespace Modules\SupportAccess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use RuntimeException;

/**
 * Append-only audit row. Updates/deletes are blocked at the application layer
 * (and via PostgreSQL trigger when DB posture migration is applied).
 */
class SupportSessionEvent extends Model
{
    public $timestamps = false;

    protected $table = 'support_session_events';

    protected $fillable = [
        'support_session_id',
        'actor_user_id',
        'effective_tenant_id',
        'event_type',
        'module',
        'page',
        'entity_type',
        'entity_id',
        'action',
        'metadata',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('support_session_events is append-only; updates are not allowed.');
        });

        static::deleting(static function (): void {
            throw new RuntimeException('support_session_events is append-only; deletes are not allowed.');
        });
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SupportSession::class, 'support_session_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'effective_tenant_id');
    }
}
