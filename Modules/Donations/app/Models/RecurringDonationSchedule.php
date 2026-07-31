<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class RecurringDonationSchedule extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'recurring_donation_schedules';

    protected $fillable = [
        'tenant_id',
        'donor_id',
        'family_id',
        'donation_category_id',
        'amount',
        'currency',
        'frequency',
        'next_run_on',
        'end_on',
        'status',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'next_run_on' => 'date',
        'end_on' => 'date',
        'metadata' => 'array',
    ];
}
