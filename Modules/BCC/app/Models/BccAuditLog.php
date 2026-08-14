<?php

namespace Modules\BCC\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenants\Models\Tenant;

class BccAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bcc_audit_logs';

    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'support_session_id',
        'event',
        'target_type',
        'target_id',
        'bcc_id',
        'old_values',
        'new_values',
        'metadata',
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

    public function bcc(): BelongsTo
    {
        return $this->belongsTo(BCC::class, 'bcc_id');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForBcc($query, string $bccId)
    {
        return $query->where('bcc_id', $bccId);
    }
}
