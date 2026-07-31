<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class Donor extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'donors';

    protected $fillable = [
        'tenant_id',
        'family_id',
        'family_member_id',
        'name',
        'email',
        'phone',
        'donor_type',
        'is_anonymous',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_anonymous' => 'boolean',
    ];
}
