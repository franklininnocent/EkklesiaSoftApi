<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Tenants\Models\Tenant;

class BillingInvoice extends Model
{
    public const STATUS_ISSUED = 'issued';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    protected $table = 'billing_invoices';

    protected $fillable = [
        'tenant_id',
        'billing_order_id',
        'invoice_number',
        'status',
        'net_amount',
        'tax_amount',
        'gross_amount',
        'amount_paid',
        'currency_code',
        'seller_snapshot',
        'buyer_snapshot',
        'tax_snapshot',
        'storage_path',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'seller_snapshot' => 'array',
            'buyer_snapshot' => 'array',
            'tax_snapshot' => 'array',
            'issued_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BillingOrder::class, 'billing_order_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillingInvoiceLine::class, 'billing_invoice_id');
    }

    public function outstandingAmount(): string
    {
        return bcsub((string) $this->gross_amount, (string) $this->amount_paid, 2);
    }
}
