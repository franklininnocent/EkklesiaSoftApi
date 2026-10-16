<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenants\Models\Tenant;

class BillingReceipt extends Model
{
    public const SOURCE_ONLINE = 'online';

    public const SOURCE_OFFLINE = 'offline';

    public const SOURCE_ADMIN = 'admin';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PARTIALLY_ALLOCATED = 'partially_allocated';

    public const STATUS_FULLY_ALLOCATED = 'fully_allocated';

    public const STATUS_REVERSED = 'reversed';

    protected $table = 'billing_receipts';

    protected $fillable = [
        'tenant_id',
        'source',
        'billing_payment_attempt_id',
        'billing_offline_claim_id',
        'amount',
        'currency_code',
        'status',
        'external_reference',
        'confirmed_by',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
