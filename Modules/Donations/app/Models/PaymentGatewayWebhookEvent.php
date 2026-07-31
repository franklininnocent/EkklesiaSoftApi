<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class PaymentGatewayWebhookEvent extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'payment_gateway_webhook_events';

    protected $fillable = [
        'tenant_id',
        'provider',
        'event_type',
        'event_id',
        'signature',
        'status',
        'payload',
        'processed_at',
        'error_message',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
