<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingCreditNote extends Model
{
    protected $table = 'billing_credit_notes';

    protected $fillable = [
        'billing_invoice_id',
        'billing_refund_id',
        'credit_note_number',
        'amount',
        'currency_code',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }
}
