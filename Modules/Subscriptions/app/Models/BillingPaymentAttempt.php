<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPaymentAttempt extends Model
{
    public const STATUS_CREATED = 'created';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_CAPTURED = 'captured';

    public const STATUS_FAILED = 'failed';

    protected $table = 'billing_payment_attempts';

    protected $fillable = [
        'billing_order_id',
        'provider_payment_id',
        'status',
        'amount',
        'currency_code',
        'environment',
        'verified_at',
        'provider_payload_summary',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'provider_payload_summary' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BillingOrder::class, 'billing_order_id');
    }
}
