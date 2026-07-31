<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class DonationReceipt extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'donation_receipts';

    protected $fillable = [
        'tenant_id',
        'payment_id',
        'receipt_number',
        'issued_on',
        'is_void',
        'void_reason',
        'issued_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'is_void' => 'boolean',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(DonationPayment::class, 'payment_id');
    }
}
