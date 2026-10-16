<?php

namespace Modules\EcclesiasticalData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EcclesiasticalOffice extends Model
{
    use HasUuids;

    protected $table = 'ecclesiastical_offices';

    protected $fillable = [
        'office_code',
        'title',
        'scope_type',
        'adapter_key',
        'allows_concurrent',
        'requires_platform_approval',
        'sort_order',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'allows_concurrent' => 'boolean',
        'requires_platform_approval' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];
}
