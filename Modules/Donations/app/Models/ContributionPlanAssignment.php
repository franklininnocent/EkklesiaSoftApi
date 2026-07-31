<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;
use Modules\Family\Models\Family;

class ContributionPlanAssignment extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'contribution_plan_assignments';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'family_id',
        'amount',
        'effective_from',
        'effective_to',
        'is_exempt',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_exempt' => 'boolean',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ContributionPlan::class, 'plan_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'family_id');
    }

    public function isEffectiveOn(string $date): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->effective_from->toDateString() > $date) {
            return false;
        }

        if ($this->effective_to && $this->effective_to->toDateString() < $date) {
            return false;
        }

        return true;
    }
}
