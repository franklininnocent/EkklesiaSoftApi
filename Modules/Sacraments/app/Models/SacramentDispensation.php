<?php

namespace Modules\Sacraments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Tenants\Models\Tenant;

class SacramentDispensation extends Model
{
    use SoftDeletes;

    protected $table = 'sacrament_dispensations';

    protected $fillable = [
        'tenant_id',
        'sacrament_id',
        'dispensation_type',
        'granting_authority',
        'protocol_number',
        'date_granted',
    ];

    protected $casts = [
        'date_granted' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sacrament(): BelongsTo
    {
        return $this->belongsTo(Sacrament::class);
    }
}
