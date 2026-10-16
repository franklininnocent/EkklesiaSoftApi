<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class ContributionCategory extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'contribution_categories';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'description',
        'active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];
}
