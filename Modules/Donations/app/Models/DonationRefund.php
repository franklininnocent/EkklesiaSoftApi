<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class DonationRefund extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'donation_refunds';

    protected $fillable = [
        'tenant_id',
        'payment_id',
        'approval_id',
        'amount',
        'refund_date',
        'status',
        'reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refund_date' => 'date',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(DonationPayment::class, 'payment_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(DonationApproval::class, 'approval_id');
    }
}
