<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;
use Modules\Family\Models\Family;

class Donation extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

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

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'family_id');
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class, 'donor_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DonationCategory::class, 'donation_category_id');
    }
}
