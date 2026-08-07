<?php

namespace Modules\MinistriesAssociations\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\MinistriesAssociations\Models\Concerns\BelongsToTenant;
use Modules\Tenants\Models\Tenant;

class OrganizationType extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'ma_organization_types';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'description',
        'is_system',
        'is_active',
        'display_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
