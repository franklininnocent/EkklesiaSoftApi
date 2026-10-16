<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;

class BillingWebhookEvent extends Model
{
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected_signature';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_FAILED = 'failed';

    protected $table = 'billing_webhook_events';

    protected $fillable = [
        'provider_event_id',
        'environment',
        'event_type',
        'signature_valid',
        'processing_status',
        'attempts',
        'available_at',
        'payload_summary',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'available_at' => 'datetime',
            'payload_summary' => 'array',
        ];
    }
}
