<?php
/**
 * Created by PhpStorm.
 * User: franklin
 * Date: 10/10/25
 * Time: 12:59 PM
 */

namespace Modules\Authentication\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Address;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, HasFactory, SoftDeletes;

    protected $table = 'users';

    /**
     * User type constants
     */
    public const USER_TYPE_PRIMARY_CONTACT = 1;      // Primary contact for tenant
    public const USER_TYPE_SECONDARY_CONTACT = 2;    // Secondary contact for tenant

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'contact_number',
        'user_type',
        'role_id',
        'tenant_id',
        'active',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'active' => 'integer',
        'role_id' => 'integer',
        'tenant_id' => 'integer',
        'user_type' => 'integer',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the role that owns the user.
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Get the tenant that owns the user.
     */
    public function tenant()
    {
        return $this->belongsTo(\Modules\Tenants\Models\Tenant::class, 'tenant_id');
    }

    /**
     * Get permissions assigned directly to this user.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_user')
            ->withTimestamps();
    }

    /**
     * Get all addresses for this user (polymorphic).
     */
    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    /**
     * Get the primary address for this user.
     */
    public function primaryAddress()
    {
        return $this->morphMany(Address::class, 'addressable')
            ->where('address_type', 'primary')
            ->where('active', 1)
            ->first();
    }

    /**
     * Get the secondary address for this user.
     */
    public function secondaryAddress()
    {
        return $this->morphMany(Address::class, 'addressable')
            ->where('address_type', 'secondary')
            ->where('active', 1)
            ->first();
    }

    /**
     * Get all active addresses for this user.
     */
    public function activeAddresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable')
            ->where('active', 1);
    }

    /**
     * Check if user is active
     */
    public function isActive(): bool
    {
        return $this->active === 1;
    }

    /**
     * Check if user is Super Admin
     */
    public function isSuperAdmin(): bool
    {
        return $this->role && $this->role->name === Role::SUPER_ADMIN;
    }

    /**
     * Check if user is Ekklesia Admin
     */
    public function isEkklesiaAdmin(): bool
    {
        return $this->role && $this->role->name === Role::EKKLESIA_ADMIN;
    }

    /**
     * Check if user is Ekklesia Manager
     */
    public function isEkklesiaManager(): bool
    {
        return $this->role && $this->role->name === Role::EKKLESIA_MANAGER;
    }

    /**
     * Check if user has admin privileges (Super Admin or Ekklesia Admin)
     */
    public function isAdmin(): bool
    {
        return $this->isSuperAdmin() || $this->isEkklesiaAdmin();
    }

    /**
     * Check if user is a primary contact.
     */
    public function isPrimaryContact(): bool
    {
        return $this->user_type === self::USER_TYPE_PRIMARY_CONTACT;
    }

    /**
     * Check if user is a secondary contact.
     */
    public function isSecondaryContact(): bool
    {
        return $this->user_type === self::USER_TYPE_SECONDARY_CONTACT;
    }

    /**
     * Check if user is any type of contact (primary or secondary).
     */
    public function isContact(): bool
    {
        return in_array($this->user_type, [self::USER_TYPE_PRIMARY_CONTACT, self::USER_TYPE_SECONDARY_CONTACT]);
    }

    /**
     * Get user type as readable string.
     */
    public function getUserTypeLabel(): string
    {
        return match($this->user_type) {
            self::USER_TYPE_PRIMARY_CONTACT => 'Primary Contact',
            self::USER_TYPE_SECONDARY_CONTACT => 'Secondary Contact',
            default => 'Unknown'
        };
    }

    /**
     * Check if user has an address.
     */
    public function hasAddress(): bool
    {
        return $this->addresses()->exists();
    }

    /**
     * Get user with all related data (eager loading).
     */
    public static function withFullData()
    {
        return self::with(['role', 'tenant', 'addresses', 'permissions']);
    }

    /**
     * Scope a query to only include active users.
     */
    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }

    /**
     * Scope a query to only include users of a specific type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('user_type', $type);
    }

    /**
     * Scope a query to only include contact users.
     */
    public function scopeContacts($query)
    {
        return $query->whereIn('user_type', ['primary_contact', 'secondary_contact']);
    }

    /**
     * Scope a query to only include inactive users.
     */
    public function scopeInactive($query)
    {
        return $query->where('active', 0);
    }

    /**
     * Scope a query to only include users by role.
     */
    public function scopeByRole($query, $roleName)
    {
        return $query->whereHas('role', function ($q) use ($roleName) {
            $q->where('name', $roleName);
        });
    }

    /**
     * Scope a query to only include users by tenant.
     */
    public function scopeByTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Activate the user
     */
    public function activate(): bool
    {
        $this->active = 1;
        return $this->save();
    }

    /**
     * Deactivate the user
     */
    public function deactivate(): bool
    {
        $this->active = 0;
        return $this->save();
    }

    /**
     * Give permission directly to this user.
     */
    public function givePermissionTo(...$permissions): self
    {
        $permissions = collect($permissions)
            ->flatten()
            ->map(function ($permission) {
                if ($permission instanceof Permission) {
                    return $permission;
                }
                return Permission::where('name', $permission)->firstOrFail();
            })
            ->each(function ($permission) {
                $this->permissions()->syncWithoutDetaching([$permission->id]);
            });

        return $this;
    }

    /**
     * Remove permission from this user.
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

        return $this;
    }

    /**
     * Check if user has a specific permission (via role or direct assignment).
     */
    public function hasPermissionTo($permission): bool
    {
        // SuperAdmin has all permissions
        if ($this->isSuperAdmin()) {
            return true;
        }

        // Check direct permissions
        if (is_string($permission)) {
            if ($this->permissions->contains('name', $permission)) {
                return true;
            }
        } else {
            if ($this->permissions->contains($permission)) {
                return true;
            }
        }

        // Check role permissions
        if ($this->role) {
            return $this->role->hasPermissionTo($permission);
        }

        return false;
    }

    /**
     * Check if user has any of the given permissions.
     */
    public function hasAnyPermission(...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermissionTo($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has all of the given permissions.
     */
    public function hasAllPermissions(...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermissionTo($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all permissions for this user (from role + direct).
     */
    public function getAllPermissions()
    {
        $permissions = collect([]);

        // Add direct permissions
        $permissions = $permissions->merge($this->permissions);

        // Add role permissions
        if ($this->role) {
            $permissions = $permissions->merge($this->role->permissions);
        }

        return $permissions->unique('id');
    }

    /**
     * Get all permission names for this user.
     */
    public function getPermissionNames(): array
    {
        return $this->getAllPermissions()->pluck('name')->toArray();
    }

    /**
     * Sync permissions with this user.
     */
    public function syncPermissions(...$permissions): self
    {
        $this->permissions()->detach();

        return $this->givePermissionTo($permissions);
    }
}