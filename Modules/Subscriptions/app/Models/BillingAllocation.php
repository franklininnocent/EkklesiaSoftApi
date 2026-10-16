<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingAllocation extends Model
{
    protected $table = 'billing_allocations';

    protected $fillable = [
        'billing_receipt_id',
        'billing_invoice_id',
        'amount',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(BillingReceipt::class, 'billing_receipt_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }
}
