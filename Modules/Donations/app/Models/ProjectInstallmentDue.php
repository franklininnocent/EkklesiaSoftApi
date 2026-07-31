<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;
use Modules\Family\Models\Family;

class ProjectInstallmentDue extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'project_installment_dues';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'family_id',
        'installment_number',
        'installment_label',
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(DonationProject::class, 'project_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'family_id');
    }
}
