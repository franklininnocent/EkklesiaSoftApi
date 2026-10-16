<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;

class BillingAuditEvent extends Model
{
    protected $table = 'billing_audit_events';

    protected $fillable = [
        'category',
        'actor_id',
        'actor_role',
        'tenant_id',
        'action',
        'reason',
        'before',
        'after',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }
}
