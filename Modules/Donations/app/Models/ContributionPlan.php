<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class ContributionPlan extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'contribution_plans';

    protected $fillable = [
        'tenant_id',
        'fund_id',
        'name',
        'code',
        'plan_type',
        'frequency',
        'custom_interval_days',
        'default_amount',
        'start_date',
        'end_date',
        'grace_days',
        'auto_generate',
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'default_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'auto_generate' => 'boolean',
    ];

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class, 'fund_id');
    }

    public function dues(): HasMany
    {
        return $this->hasMany(ContributionDue::class, 'plan_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ContributionPlanAssignment::class, 'plan_id');
    }

    public function revisionHistory(): HasMany
    {
        return $this->hasMany(ContributionPlanRevisionHistory::class, 'plan_id');
    }
}
