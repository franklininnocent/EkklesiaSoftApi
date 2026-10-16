<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingInvoiceLine extends Model
{
    protected $table = 'billing_invoice_lines';

    protected $fillable = [
        'billing_invoice_id',
        'description',
        'quantity',
        'unit_amount',
        'tax_amount',
        'line_total',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }
}
