<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class DonationProject extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'donation_projects';

    protected $fillable = [
        'tenant_id',
        'fund_id',
        'name',
        'code',
        'entity_kind',
        'campaign_type',
        'assignment_mode',
        'description',
        'target_amount',
        'default_family_target',
        'raised_amount',
        'start_date',
        'end_date',
        'installment_count',
        'installment_frequency',
        'installment_interval_days',
        'auto_generate_installments',
        'status',
        'completed_at',
        'is_tax_deductible',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'default_family_target' => 'decimal:2',
        'raised_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'auto_generate_installments' => 'boolean',
        'completed_at' => 'datetime',
        'is_tax_deductible' => 'boolean',
    ];

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class, 'fund_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProjectFamilyAssignment::class, 'project_id');
    }

    public function installmentDues(): HasMany
    {
        return $this->hasMany(ProjectInstallmentDue::class, 'project_id');
    }
}
