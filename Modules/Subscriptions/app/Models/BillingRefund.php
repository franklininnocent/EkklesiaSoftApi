<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingRefund extends Model
{
    protected $table = 'billing_refunds';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'tenant_id',
        'billing_payment_attempt_id',
        'billing_receipt_id',
        'amount',
        'currency_code',
        'status',
        'reason',
        'requested_by',
        'approved_by',
        'provider_refund_id',
    ];

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(BillingPaymentAttempt::class, 'billing_payment_attempt_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(BillingReceipt::class, 'billing_receipt_id');
    }
}
