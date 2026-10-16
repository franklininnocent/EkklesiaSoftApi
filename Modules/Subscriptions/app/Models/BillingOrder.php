<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Tenants\Models\Tenant;

class BillingOrder extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_PAYMENT_PENDING = 'payment_pending';

    public const STATUS_CAPTURED = 'captured';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NEEDS_INVESTIGATION = 'needs_investigation';

    protected $table = 'billing_orders';

    protected $fillable = [
        'tenant_id',
        'created_by_user_id',
        'plan_version_id',
        'plan_id',
        'billing_interval',
        'idempotency_key',
        'internal_reference',
        'receipt',
        'environment',
        'provider',
        'provider_order_id',
        'status',
        'net_amount',
        'tax_amount',
        'gross_amount',
        'amount_minor',
        'currency_code',
        'tax_snapshot',
        'commercial_snapshot',
        'fulfillment_key',
        'expires_at',
        'fulfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'tax_snapshot' => 'array',
            'commercial_snapshot' => 'array',
            'expires_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class, 'plan_version_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(BillingPaymentAttempt::class, 'billing_order_id');
    }
}
