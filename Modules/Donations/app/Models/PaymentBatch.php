<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class PaymentBatch extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'payment_batches';

    protected $fillable = [
        'tenant_id',
        'batch_number',
        'batch_date',
        'source',
        'status',
        'payments_count',
        'total_amount',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'batch_date' => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(DonationPayment::class, 'payment_batch_id');
    }
}
