<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Model;

class DonationAuditLog extends Model
{
    protected $table = 'donation_audit_logs';

    protected $fillable = [
        'tenant_id',
        'actor_user_id',
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
    ];
}
