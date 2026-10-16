<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenants\Models\Tenant;

class BillingCredit extends Model
{
    protected $table = 'billing_credits';

    protected $fillable = [
        'tenant_id',
        'billing_receipt_id',
        'amount',
        'amount_remaining',
        'currency_code',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
