<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MassIntentionOfferingReceipt extends Model
{
    use HasUuids;

    protected $table = 'mass_intention_offering_receipts';

    protected $fillable = [
        'tenant_id',
        'offering_id',
        'receipt_number',
        'amount',
        'payment_method',
        'received_on',
        'voided_at',
        'void_reason',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(MassIntentionOffering::class, 'offering_id');
    }
}
