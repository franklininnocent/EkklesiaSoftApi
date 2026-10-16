<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenants\Models\Tenant;

class TenantBillingPolicy extends Model
{
    protected $table = 'tenant_billing_policies';

    protected $fillable = [
        'tenant_id',
        'online_checkout',
        'offline_claims',
        'purchases_restricted',
        'internal_note',
    ];

    protected function casts(): array
    {
        return [
            'purchases_restricted' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
