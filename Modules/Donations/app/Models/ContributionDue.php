<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;
use Modules\Family\Models\Family;

class ContributionDue extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'contribution_dues';

    protected $fillable = [
        'tenant_id',
        'family_id',
        'plan_id',
        'period_label',
        'due_date',
        'amount_due',
        'amount_paid',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'due_date' => 'date',
        'amount_due' => 'decimal:2',
        'amount_paid' => 'decimal:2',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'family_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ContributionPlan::class, 'plan_id');
    }
}
