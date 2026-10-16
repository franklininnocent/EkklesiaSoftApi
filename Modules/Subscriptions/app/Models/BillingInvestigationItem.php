<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenants\Models\Tenant;

class BillingInvestigationItem extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $table = 'billing_investigation_items';

    protected $fillable = [
        'category',
        'tenant_id',
        'status',
        'details',
        'assigned_to',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
