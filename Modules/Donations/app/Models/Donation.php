<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class Donation extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'donations';

    protected $fillable = [
        'tenant_id',
        'donor_id',
        'family_id',
        'family_member_id',
        'donation_category_id',
        'project_id',
        'title',
        'pledged_amount',
        'collected_amount',
        'received_at',
        'financial_year',
        'status',
        'notes',
        'is_anonymous',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'pledged_amount' => 'decimal:2',
        'collected_amount' => 'decimal:2',
        'received_at' => 'date',
        'is_anonymous' => 'boolean',
    ];

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class, 'donor_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DonationCategory::class, 'donation_category_id');
    }
}
