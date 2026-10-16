<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenants\Models\Tenant;

class BillingOfflineClaim extends Model
{
    public const STATUS_PENDING = 'pending_verification';

    public const STATUS_INFO_REQUIRED = 'information_required';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DUPLICATE = 'duplicate_suspected';

    public const STATUS_INVESTIGATION = 'under_investigation';

    protected $table = 'billing_offline_claims';

    protected $fillable = [
        'tenant_id',
        'submitted_by_user_id',
        'billing_invoice_id',
        'billing_order_id',
        'method_code',
        'claimed_amount',
        'currency_code',
        'paid_on',
        'reference_number',
        'remitter_name',
        'notes',
        'status',
        'evidence_storage_path',
        'reviewed_by',
        'second_approved_by',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
