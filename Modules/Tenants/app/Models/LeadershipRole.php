<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Tenants\Database\Factories\LeadershipRoleFactory;

class LeadershipRole extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'leadership_roles';

    protected $fillable = [
        'tenant_id',
        'title',
        'category',
        'hierarchical_level',
        'allows_concurrent',
        'is_canonical_mandate',
        'is_active',
    ];

    protected $casts = [
        'hierarchical_level' => 'integer',
        'allows_concurrent' => 'boolean',
        'is_canonical_mandate' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function newFactory(): LeadershipRoleFactory
    {
        return LeadershipRoleFactory::new();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LeadershipAssignment::class, 'role_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAccessibleToTenant($query, int $tenantId)
    {
        return $query->where(function ($q) use ($tenantId): void {
            $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
        });
    }
}
