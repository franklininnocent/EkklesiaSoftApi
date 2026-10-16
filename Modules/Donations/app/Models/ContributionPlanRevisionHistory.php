<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Family\Models\Family;

class ContributionPlanRevisionHistory extends Model
{
    protected $table = 'contribution_plan_revision_history';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'family_id',
        'change_type',
        'old_amount',
        'new_amount',
        'effective_from',
        'reason',
        'changed_by',
    ];

    protected $casts = [
        'old_amount' => 'decimal:2',
        'new_amount' => 'decimal:2',
        'effective_from' => 'date',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ContributionPlan::class, 'plan_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'family_id');
    }
}
