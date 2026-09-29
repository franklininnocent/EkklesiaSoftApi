<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class DonationReceipt extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'donation_receipts';

    protected $fillable = [
        'tenant_id',
        'payment_id',
        'receipt_number',
        'issued_on',
        'is_void',
        'void_reason',
        'snapshot',
        'replaces_receipt_id',
        'replaced_by_receipt_id',
        'voided_at',
        'voided_by',
        'issued_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'is_void' => 'boolean',
        'snapshot' => 'array',
        'voided_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(DonationPayment::class, 'payment_id');
    }
}
