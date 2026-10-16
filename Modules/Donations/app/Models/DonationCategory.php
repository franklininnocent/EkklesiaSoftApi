<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class DonationCategory extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'donation_categories';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'description',
        'is_tax_deductible',
        'active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_tax_deductible' => 'boolean',
    ];
}
