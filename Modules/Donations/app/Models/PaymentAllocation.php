<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class PaymentAllocation extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'payment_allocations';

    protected $fillable = [
        'tenant_id',
        'payment_id',
        'allocatable_type',
        'allocatable_id',
        'amount',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(DonationPayment::class, 'payment_id');
    }
}
