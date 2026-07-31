<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;
use Modules\Family\Models\Family;

class DonationPayment extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'donation_payments';

    protected $fillable = [
        'tenant_id',
        'family_id',
        'donor_id',
        'payment_batch_id',
        'payment_number',
        'payer_name',
        'payer_email',
        'payer_phone',
        'payment_date',
        'amount',
        'currency',
        'method',
        'gateway_reference',
        'status',
        'source_type',
        'is_anonymous',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'is_anonymous' => 'boolean',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'family_id');
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class, 'donor_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PaymentBatch::class, 'payment_batch_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(DonationReceipt::class, 'payment_id');
    }
}
