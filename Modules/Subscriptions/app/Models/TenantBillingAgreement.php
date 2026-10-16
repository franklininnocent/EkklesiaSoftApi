<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenants\Models\Tenant;

class TenantBillingAgreement extends Model
{
    public const ENTITLEMENT_WHEN_FULLY_PAID = 'when_fully_paid';

    public const ENTITLEMENT_ON_FIRST_RECEIPT = 'on_first_confirmed_receipt';

    protected $table = 'tenant_billing_agreements';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'billing_interval',
        'amount',
        'currency_code',
        'entitlement_on_partial',
        'installment_schedule',
        'effective_from',
        'effective_until',
        'authorized_by',
        'reason',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'installment_schedule' => 'array',
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
