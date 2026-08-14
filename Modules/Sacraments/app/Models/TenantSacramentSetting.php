<?php

namespace Modules\Sacraments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;

class TenantSacramentSetting extends Model
{
    protected $table = 'tenant_sacrament_settings';

    protected $fillable = [
        'tenant_id',
        'sacrament_type_id',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sacramentType(): BelongsTo
    {
        return $this->belongsTo(SacramentType::class, 'sacrament_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
