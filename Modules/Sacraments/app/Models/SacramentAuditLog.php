<?php

namespace Modules\Sacraments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;

class SacramentAuditLog extends Model
{
    protected $table = 'sacrament_audit_logs';

    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'support_session_id',
        'event',
        'target_type',
        'target_id',
        'old_values',
        'new_values',
        'metadata',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
