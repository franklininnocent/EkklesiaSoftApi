<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenants\Models\Tenant;

class BillingTenantPriceAssignment extends Model
{
    protected $table = 'billing_tenant_price_assignments';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'billing_promotion_id',
        'billing_interval',
        'negotiated_amount',
        'currency_code',
        'scope',
        'renewal_policy',
        'discounted_periods',
        'effective_from',
        'effective_until',
        'active',
        'authorized_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
