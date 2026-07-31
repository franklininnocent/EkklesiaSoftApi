<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class Fund extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'donation_funds';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'description',
        'is_tax_deductible',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_tax_deductible' => 'boolean',
    ];

    public function plans(): HasMany
    {
        return $this->hasMany(ContributionPlan::class, 'fund_id');
    }
}
