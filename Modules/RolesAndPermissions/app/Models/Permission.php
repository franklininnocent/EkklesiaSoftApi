<?php

namespace Modules\RolesAndPermissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;

class Permission extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'permissions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'display_name',
        'description',
        'module',
        'category',
        'tenant_id',
        'is_custom',
        'active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'active' => 'integer',
        'tenant_id' => 'integer',
        'is_custom' => 'boolean',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the roles that have this permission.
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'permission_role')
            ->withTimestamps();
    }

    /**
     * Get the users that have this permission directly assigned.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'permission_user')
            ->withTimestamps();
    }

    /**
     * Get the tenant that owns the permission.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Scope a query to only include active permissions.
     */
    public function scopeActive($query)
    {
        return $query->where('active', 1)->whereNull('deleted_at');
    }

    /**
     * Scope a query to only include inactive permissions.
     */
    public function scopeInactive($query)
    {
        return $query->where('active', 0);
    }

    /**
     * Scope a query to only include global permissions (system permissions).
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('tenant_id')->where('is_custom', 0);
    }

    /**
     * Scope a query to only include tenant-specific permissions.
     */
    public function scopeByTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query by module.
     */
    public function scopeByModule($query, $module)
    {
        return $query->where('module', $module);
    }

    /**
     * Scope a query by category.
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope a query to only include custom permissions.
     */
    public function scopeCustom($query)
    {
        return $query->where('is_custom', 1);
    }

    /**
     * Scope a query to only include system permissions.
     */
    public function scopeSystem($query)
    {
        return $query->where('is_custom', 0);
    }

    /**
     * Check if permission is active.
     */
    public function isActive(): bool
    {
        return $this->active === 1;
    }

    /**
     * Check if permission is a global/system permission.
     */
    public function isGlobal(): bool
    {
        return is_null($this->tenant_id) && !$this->is_custom;
    }

    /**
     * Check if permission is a custom permission.
     */
    public function isCustom(): bool
    {
        return $this->is_custom === true;
    }

    /**
     * Activate the permission.
     */
    public function activate(): bool
    {
        return $this->update(['active' => 1]);
    }

    /**
     * Deactivate the permission.
     */
    public function deactivate(): bool
    {
        return $this->update(['active' => 0]);
    }

    /**
     * Assign this permission to a role.
     */
    public function assignToRole(Role $role): void
    {
        if (!$this->roles->contains($role->id)) {
            $this->roles()->attach($role->id);
        }
    }

    /**
     * Remove this permission from a role.
     */
    public function removeFromRole(Role $role): void
    {
        $this->roles()->detach($role->id);
    }

    /**
     * Assign this permission directly to a user.
     */
    public function assignToUser(User $user): void
    {
        if (!$this->users->contains($user->id)) {
            $this->users()->attach($user->id);
        }
    }

    /**
     * Remove this permission from a user.
     */
    public function removeFromUser(User $user): void
    {
        $this->users()->detach($user->id);
    }
}

