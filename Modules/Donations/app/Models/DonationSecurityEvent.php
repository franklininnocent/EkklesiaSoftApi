<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Model;

class DonationSecurityEvent extends Model
{
    protected $table = 'donation_security_events';

    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'event',
        'method',
        'path',
        'target_type',
        'target_id',
        'http_status',
        'request_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'http_status' => 'integer',
    ];
}
