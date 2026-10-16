<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;
use Modules\Family\Models\Family;

class ProjectFamilyAssignment extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'project_family_assignments';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'family_id',
        'target_amount',
        'amount_collected',
        'is_exempt',
        'effective_from',
        'effective_to',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'amount_collected' => 'decimal:2',
        'is_exempt' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(DonationProject::class, 'project_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'family_id');
    }

    public function installmentDues(): HasMany
    {
        return $this->hasMany(ProjectInstallmentDue::class, 'family_id', 'family_id')
            ->whereColumn('project_installment_dues.project_id', 'project_family_assignments.project_id');
    }
}
