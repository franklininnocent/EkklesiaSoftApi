<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Cache;
use Modules\RolesAndPermissions\Models\Permission;

class Role extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'roles';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'level',
        'active',
        'tenant_id',
        'is_custom',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'active' => 'integer',
        'level' => 'integer',
        'tenant_id' => 'integer',
        'is_custom' => 'boolean',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Role level constants
     */
    const LEVEL_SUPER_ADMIN = 1;
    const LEVEL_EKKLESIA_ADMIN = 2;
    const LEVEL_EKKLESIA_MANAGER = 3;
    const LEVEL_EKKLESIA_USER = 4;

    /**
     * Role name constants
     */
    const SUPER_ADMIN = 'SuperAdmin';
    const EKKLESIA_ADMIN = 'EkklesiaAdmin';
    const EKKLESIA_MANAGER = 'EkklesiaManager';
    const EKKLESIA_USER = 'EkklesiaUser';

    /**
     * Get the users for the role.
     */
    public function users()
    {
        return $this->hasMany(User::class, 'role_id');
    }

    /**
     * Get the tenant that owns the role.
     */
    public function tenant()
    {
        return $this->belongsTo(\Modules\Tenants\Models\Tenant::class, 'tenant_id');
    }

    /**
     * Get the permissions assigned to this role.
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'permission_role')
            ->withTimestamps();
    }

    /**
     * Scope a query to only include active roles.
     */
    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }

    /**
     * Scope a query to only include inactive roles.
     */
    public function scopeInactive($query)
    {
        return $query->where('active', 0);
    }

    /**
     * Scope a query to only include global roles (system roles).
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('tenant_id')->where('is_custom', 0);
    }

    /**
     * Scope a query to only include tenant-specific roles.
     */
    public function scopeByTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query to only include custom roles.
     */
    public function scopeCustom($query)
    {
        return $query->where('is_custom', 1);
    }

    /**
     * Scope a query to only include system roles.
     */
    public function scopeSystem($query)
    {
        return $query->where('is_custom', 0);
    }

    /**
     * Check if role is Super Admin
     */
    public function isSuperAdmin(): bool
    {
        return $this->name === self::SUPER_ADMIN;
    }

    /**
     * Check if role is Ekklesia Admin
     */
    public function isEkklesiaAdmin(): bool
    {
        return $this->name === self::EKKLESIA_ADMIN;
    }

    /**
     * Check if role is active
     */
    public function isActive(): bool
    {
        return $this->active === 1;
    }

    /**
     * Check if role is a global/system role
     */
    public function isGlobal(): bool
    {
        return is_null($this->tenant_id) && !$this->is_custom;
    }

    /**
     * Check if role is a custom role
     */
    public function isCustom(): bool
    {
        return $this->is_custom === true;
    }

    /**
     * Activate the role
     */
    public function activate(): bool
    {
        $this->active = 1;
        return $this->save();
    }

    /**
     * Deactivate the role
     */
    public function deactivate(): bool
    {
        $this->active = 0;
        return $this->save();
    }

    /**
     * Give permission to this role.
     * 
     * SECURITY: Validates tenant isolation - permissions must belong to role's tenant or be system-wide
     * 
     * @throws \RuntimeException If permission doesn't belong to role's tenant
     */
    public function givePermissionTo(...$permissions): self
    {
        $permissionObjects = collect($permissions)
            ->flatten()
            ->map(function ($permission) {
                if ($permission instanceof Permission) {
                    return $permission;
                }
                return Permission::where('name', $permission)->firstOrFail();
            })
            ->each(function ($permission) {
                // SECURITY: Validate tenant isolation
                // Permission must be active and not deleted
                if ($permission->active !== 1 || $permission->deleted_at !== null) {
                    throw new \RuntimeException("Cannot assign inactive or deleted permission: {$permission->name}");
                }
                
                // Permission must be system-wide (tenant_id = null) OR belong to role's tenant
                if (!is_null($permission->tenant_id)) {
                    // Permission is tenant-specific
                    if (is_null($this->tenant_id)) {
                        // Role is global, cannot assign tenant-specific permission
                        throw new \RuntimeException(
                            "Cannot assign tenant-specific permission to global role. Permission: {$permission->name} (tenant_id: {$permission->tenant_id})"
                        );
                    }
                    if ($permission->tenant_id !== $this->tenant_id) {
                        // Permission belongs to different tenant
                        throw new \RuntimeException(
                            "Cannot assign permission from different tenant. Permission: {$permission->name} (tenant_id: {$permission->tenant_id}), Role tenant_id: {$this->tenant_id}"
                        );
                    }
                }
                
                $this->permissions()->syncWithoutDetaching([$permission->id]);
            });
        
        // PERFORMANCE: Clear permission cache for all users with this role
        $this->clearUsersPermissionCache();

        return $this;
    }

    /**
     * Remove permission from this role.
     */
    public function revokePermissionTo(...$permissions): self
    {
        collect($permissions)
            ->flatten()
            ->map(function ($permission) {
                if ($permission instanceof Permission) {
                    return $permission;
                }
                return Permission::where('name', $permission)->firstOrFail();
            })
            ->each(function ($permission) {
                $this->permissions()->detach($permission->id);
            });
        
        // PERFORMANCE: Clear permission cache for all users with this role
        $this->clearUsersPermissionCache();

        return $this;
    }

    /**
     * Check if role has a specific permission.
     */
    public function hasPermissionTo($permission): bool
    {
        if (is_string($permission)) {
            return $this->permissions->contains('name', $permission);
        }

        return $this->permissions->contains($permission);
    }

    /**
     * Sync permissions with this role.
     */
    public function syncPermissions(...$permissions): self
    {
        $this->permissions()->detach();

        return $this->givePermissionTo($permissions);
    }

    /**
     * Get all permission names for this role.
     */
    public function getPermissionNames(): array
    {
        return $this->permissions->pluck('name')->toArray();
    }
    
    /**
     * Clear permission cache for all users with this role.
     * 
     * PERFORMANCE: Called when role permissions are modified to ensure
     * users get updated permissions immediately instead of waiting for cache expiry.
     * 
     * Note: Since cache keys include role hash, we need to clear all possible
     * cache keys for users. The most reliable approach is to load users and
     * call their clearPermissionsCache() method, which will regenerate the
     * correct cache key based on current roles.
     */
    public function clearUsersPermissionCache(): void
    {
        // Get all users with this role (chunked for memory efficiency)
        $this->users()->chunk(100, function ($users) {
            foreach ($users as $user) {
                // Clear cache using the user's method which knows the correct cache key
                $user->clearPermissionsCache();
            }
        });
    }
}


