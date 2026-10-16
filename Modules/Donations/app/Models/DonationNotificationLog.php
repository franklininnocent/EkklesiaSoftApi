<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class DonationNotificationLog extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'donation_notification_logs';

    protected $fillable = [
        'tenant_id',
        'notification_type',
        'channel',
        'recipient',
        'target_type',
        'target_id',
        'status',
        'payload',
        'sent_at',
        'error_message',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];
}
