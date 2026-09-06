<?php

namespace Modules\Tenants\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'church_audit_logs';

    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'support_session_id',
        'event',
        'target_type',
        'target_id',
        'church_profile_id',
        'old_values',
        'new_values',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
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
