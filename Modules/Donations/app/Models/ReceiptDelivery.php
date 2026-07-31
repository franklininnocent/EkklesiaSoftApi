<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class ReceiptDelivery extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'receipt_deliveries';

    protected $fillable = [
        'tenant_id',
        'receipt_id',
        'channel',
        'recipient',
        'status',
        'sent_at',
        'error_message',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(DonationReceipt::class, 'receipt_id');
    }
}
