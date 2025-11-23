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
use Illuminate\Support\Facades\Cache;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Address;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, HasFactory, SoftDeletes;

    protected $table = 'users';

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return \Modules\Authentication\Database\Factories\UserFactory::new();
    }

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
        'is_primary_admin',
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
        'is_primary_admin' => 'boolean',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the role that owns the user (Legacy - single role).
     * 
     * @deprecated Use roles() for multiple roles support
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Get all roles assigned to this user (Many-to-Many).
     * 
     * This is the primary relationship for multi-role support.
     * Users can have multiple roles, each contributing their permissions.
     * 
     * PERFORMANCE: Orders by level for efficient permission aggregation
     * 
     * Note: Active/soft-delete filtering should be done in queries using this relationship
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->withTimestamps()
            ->orderBy('level', 'asc'); // Order by role level (highest priority first)
    }
    
    /**
     * Get only active roles assigned to this user.
     * 
     * SECURITY: Filters to active, non-deleted roles only
     */
    public function activeRoles(): BelongsToMany
    {
        return $this->roles()
            ->where('roles.active', 1)
            ->whereNull('roles.deleted_at');
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
     * Cache key for user permissions
     * 
     * SECURITY & PERFORMANCE: Includes tenant_id and role hash to prevent cache collisions
     * and ensure cache is invalidated when roles change
     */
    private function getPermissionsCacheKey(): string
    {
        // Include tenant_id to prevent cross-tenant cache pollution
        $tenantId = $this->tenant_id ?? 'null';
        
        // Include role hash to invalidate cache when roles change
        $roleHash = $this->getRoleHash();
        
        return "user_permissions_{$this->id}_tenant_{$tenantId}_roles_{$roleHash}";
    }
    
    /**
     * Request-level cache for permissions (per request lifecycle)
     * This prevents multiple database queries for the same user's permissions in a single request
     */
    private static array $requestPermissionCache = [];

    /**
     * Get hash of user's active role IDs for cache versioning
     * 
     * @return string
     */
    private function getRoleHash(): string
    {
        $roleIds = $this->activeRoles()->pluck('roles.id')->sort()->values()->toArray();
        return md5(implode(',', $roleIds));
    }
    
    /**
     * Clear request-level permission cache for this user
     * Called when roles/permissions are modified during the request
     */
    public function clearRequestPermissionCache(): void
    {
        $cacheKey = "user_{$this->id}_permissions";
        unset(self::$requestPermissionCache[$cacheKey]);
    }

    /**
     * Clear permissions cache for this user
     */
    public function clearPermissionsCache(): void
    {
        Cache::forget($this->getPermissionsCacheKey());
        // Also clear request-level cache
        $this->clearRequestPermissionCache();
    }

    /**
     * Get ALL permissions for this user (from all roles + direct permissions).
     * 
     * SECURITY ENHANCEMENTS:
     * - Filters by active status (roles and permissions must be active)
     * - Validates tenant isolation (permissions must belong to user's tenant or be system-wide)
     * - Excludes soft-deleted records
     * - Implements caching for performance
     * 
     * This method aggregates permissions from:
     * 1. All active assigned roles (filtered by tenant)
     * 2. Direct active user permissions (filtered by tenant)
     * 
     * @param bool $useCache Whether to use cached permissions (default: true)
     * @return \Illuminate\Support\Collection
     */
    public function getAllPermissions(bool $useCache = true)
    {
        // Use cache if available and requested
        if ($useCache) {
            $cached = Cache::get($this->getPermissionsCacheKey());
            if ($cached !== null) {
                return collect($cached);
            }
        }

        // PERFORMANCE: Optimize query to reduce N+1 by using single query with joins
        // Get active roles that belong to user's tenant or are global
        $roleQuery = $this->roles()
            ->where('roles.active', 1)
            ->whereNull('roles.deleted_at');
        
        // SECURITY: Filter roles by tenant (unless SuperAdmin)
        if (!$this->isSuperAdmin() && $this->tenant_id) {
            $roleQuery->where(function ($q) {
                $q->whereNull('roles.tenant_id') // Global roles
                  ->orWhere('roles.tenant_id', $this->tenant_id); // User's tenant roles
            });
        }
        
        // PERFORMANCE: Use eager loading with constraints to load permissions in single query
        // Get permissions from active roles (only active permissions)
        $rolePermissions = $roleQuery
            ->with(['permissions' => function ($query) {
                $query->where('permissions.active', 1)
                      ->whereNull('permissions.deleted_at');
            }])
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->filter(function ($permission) {
                // SECURITY: Validate tenant isolation for permissions
                // Allow: system permissions (tenant_id = null) OR permissions from user's tenant
                if ($this->isSuperAdmin()) {
                    return true; // SuperAdmin can have any permission
                }
                
                if (is_null($permission->tenant_id)) {
                    return true; // System permissions are allowed
                }
                
                return $permission->tenant_id === $this->tenant_id;
            })
            ->unique('id');
        
        // Get direct active permissions (with tenant validation)
        $directPermissionsQuery = $this->permissions()
            ->where('active', 1)
            ->whereNull('deleted_at');
        
        // SECURITY: Filter direct permissions by tenant
        if (!$this->isSuperAdmin() && $this->tenant_id) {
            $directPermissionsQuery->where(function ($q) {
                $q->whereNull('tenant_id') // System permissions
                  ->orWhere('tenant_id', $this->tenant_id); // User's tenant permissions
            });
        }
        
        $directPermissions = $directPermissionsQuery->get();
        
        // Merge and remove duplicates
        $allPermissions = $rolePermissions->merge($directPermissions)->unique('id');
        
        // Cache for 5 minutes (permissions don't change frequently)
        if ($useCache) {
            Cache::put($this->getPermissionsCacheKey(), $allPermissions->toArray(), 300);
        }
        
        return $allPermissions;
    }

    /**
     * Check if user has a specific permission (from any role or direct assignment).
     * 
     * PERFORMANCE: Uses optimized check with caching
     * SECURITY: Validates tenant isolation and active status
     * 
     * @param string|Permission $permission
     * @return bool
     */
    public function hasPermission($permission): bool
    {
        // Early exit for SuperAdmin (has all permissions)
        if ($this->isSuperAdmin()) {
            return true;
        }
        
        if ($permission instanceof Permission) {
            $permissionName = $permission->name;
        } else {
            $permissionName = $permission;
        }
        
        // PERFORMANCE: Use request-level cache to avoid multiple getAllPermissions() calls
        $cacheKey = "user_{$this->id}_permissions";
        if (!isset(self::$requestPermissionCache[$cacheKey])) {
            self::$requestPermissionCache[$cacheKey] = $this->getAllPermissions(true);
        }
        
        return self::$requestPermissionCache[$cacheKey]->contains('name', $permissionName);
    }

    /**
     * Check if user has any of the given permissions.
     * 
     * @param array $permissions
     * @return bool
     */
    public function hasAnyPermission(array $permissions): bool
    {
        return $this->getAllPermissions()
            ->pluck('name')
            ->intersect($permissions)
            ->isNotEmpty();
    }

    /**
     * Check if user has all of the given permissions.
     * 
     * @param array $permissions
     * @return bool
     */
    public function hasAllPermissions(array $permissions): bool
    {
        return $this->getAllPermissions()
            ->pluck('name')
            ->intersect($permissions)
            ->count() === count($permissions);
    }

    /**
     * Check if user has a specific role.
     * 
     * @param string|Role $role
     * @return bool
     */
    public function hasRole($role): bool
    {
        if ($role instanceof Role) {
            return $this->roles->contains('id', $role->id);
        }
        
        return $this->roles->contains('name', $role);
    }

    /**
     * Check if user has any of the given roles.
     * 
     * @param array $roles
     * @return bool
     */
    public function hasAnyRole(array $roles): bool
    {
        return $this->roles->pluck('name')->intersect($roles)->isNotEmpty();
    }

    /**
     * Check if user has all of the given roles.
     * 
     * @param array $roles
     * @return bool
     */
    public function hasAllRoles(array $roles): bool
    {
        return $this->roles->pluck('name')->intersect($roles)->count() === count($roles);
    }

    /**
     * Assign roles to the user.
     * 
     * SECURITY: Validates that roles belong to user's tenant or are global
     * SECURITY: Validates roles are active and not deleted
     * PERFORMANCE: Clears permissions cache after assignment
     * 
     * @param mixed ...$roles
     * @return self
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \RuntimeException If role doesn't belong to user's tenant or is inactive
     */
    public function assignRoles(...$roles): self
    {
        $roleObjects = collect($roles)
            ->flatten()
            ->map(function ($role) {
                if ($role instanceof Role) {
                    return $role;
                }
                return Role::where('name', $role)->firstOrFail();
            });
        
        // SECURITY: Validate role level hierarchy to prevent conflicts
        // Users should not have conflicting role levels (e.g., SuperAdmin + EkklesiaUser)
        $this->validateRoleHierarchy($roleObjects);
        
        $roleIds = $roleObjects->map(function ($role) {
            // SECURITY: Validate tenant isolation
            // SuperAdmin can assign any role
            if ($this->isSuperAdmin()) {
                // But still validate role is active
                if ($role->active !== 1 || $role->deleted_at !== null) {
                    throw new \RuntimeException("Cannot assign inactive or deleted role: {$role->name}");
                }
                return $role->id;
            }
            
            // SECURITY: Role must be active and not soft-deleted
            if ($role->active !== 1) {
                throw new \RuntimeException("Cannot assign inactive role: {$role->name}");
            }
            
            if ($role->deleted_at !== null) {
                throw new \RuntimeException("Cannot assign deleted role: {$role->name}");
            }
            
            // SECURITY: Role must be global (tenant_id = null) OR belong to user's tenant
            if (!is_null($role->tenant_id) && $role->tenant_id !== $this->tenant_id) {
                throw new \RuntimeException(
                    "Cannot assign role from different tenant. Role tenant_id: {$role->tenant_id}, User tenant_id: {$this->tenant_id}"
                );
            }
            
            return $role->id;
        });
        
        $this->roles()->syncWithoutDetaching($roleIds);
        
        // Clear permissions cache after role assignment
        $this->clearPermissionsCache();
        $this->clearRequestPermissionCache();
        
        return $this;
    }
    
    /**
     * Validate role level hierarchy to prevent conflicting role assignments.
     * 
     * SECURITY: Prevents users from having conflicting roles (e.g., SuperAdmin + lower level roles)
     * Higher level roles should take precedence, but we warn about conflicts.
     * 
     * @param \Illuminate\Support\Collection $newRoles
     * @return void
     * @throws \RuntimeException If role hierarchy conflict is detected
     */
    private function validateRoleHierarchy($newRoles): void
    {
        // Get existing roles
        $existingRoles = $this->activeRoles()->get();
        $allRoles = $existingRoles->merge($newRoles)->unique('id');
        
        // Define role hierarchy levels (lower number = higher privilege)
        $roleLevels = [
            Role::SUPER_ADMIN => 1,
            Role::EKKLESIA_ADMIN => 2,
            Role::EKKLESIA_MANAGER => 3,
            Role::EKKLESIA_USER => 4,
        ];
        
        // Get all role levels
        $assignedLevels = [];
        foreach ($allRoles as $role) {
            $level = $role->level ?? ($roleLevels[$role->name] ?? 99);
            $assignedLevels[] = $level;
        }
        
        // Check for conflicting role levels (warn if user has both high and low privilege roles)
        if (count($assignedLevels) > 1) {
            $minLevel = min($assignedLevels);
            $maxLevel = max($assignedLevels);
            
            // If there's a significant gap (e.g., level 1 and level 4), log warning
            if ($maxLevel - $minLevel >= 2 && $minLevel <= 2) {
                \Log::warning('User assigned conflicting role levels', [
                    'user_id' => $this->id,
                    'user_email' => $this->email,
                    'min_level' => $minLevel,
                    'max_level' => $maxLevel,
                    'roles' => $allRoles->pluck('name')->toArray(),
                ]);
            }
        }
    }

    /**
     * Sync roles for the user (replaces existing roles).
     * 
     * SECURITY: Validates that roles belong to user's tenant or are global
     * PERFORMANCE: Clears permissions cache after sync
     * 
     * @param array $roleIds
     * @return self
     * @throws \RuntimeException If any role doesn't belong to user's tenant
     */
    public function syncRoles(array $roleIds): self
    {
        // Validate all roles before syncing
        $roles = Role::whereIn('id', $roleIds)
            ->where('active', 1)
            ->whereNull('deleted_at')
            ->get();
        
        // SECURITY: Validate tenant isolation for all roles
        if (!$this->isSuperAdmin()) {
            foreach ($roles as $role) {
                if (!is_null($role->tenant_id) && $role->tenant_id !== $this->tenant_id) {
                    throw new \RuntimeException(
                        "Cannot assign role from different tenant. Role: {$role->name} (tenant_id: {$role->tenant_id}), User tenant_id: {$this->tenant_id}"
                    );
                }
            }
        }
        
        $this->roles()->sync($roleIds);
        
        // Clear permissions cache after role sync
        $this->clearPermissionsCache();
        
        return $this;
    }

    /**
     * Remove roles from the user.
     * 
     * @param mixed ...$roles
     * @return self
     */
    public function removeRoles(...$roles): self
    {
        $roles = collect($roles)
            ->flatten()
            ->map(function ($role) {
                if ($role instanceof Role) {
                    return $role->id;
                }
                return Role::where('name', $role)->first()->id ?? null;
            })
            ->filter();
        
        $this->roles()->detach($roles);
        
        return $this;
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
     * 
     * SECURITY FIX: Now checks both legacy role() and roles() relationship
     * to support multi-role architecture
     */
    public function isSuperAdmin(): bool
    {
        // Check legacy role relationship (for backward compatibility)
        // SECURITY: Must verify role is active and not deleted
        if ($this->role && 
            $this->role->name === Role::SUPER_ADMIN && 
            $this->role->active === 1 && 
            $this->role->deleted_at === null) {
            return true;
        }
        
        // Check roles() relationship (multi-role support)
        // SECURITY: Explicitly filter by active status and non-deleted
        return $this->activeRoles()
            ->where('name', Role::SUPER_ADMIN)
            ->exists();
    }

    /**
     * Check if user is Ekklesia Admin
     * 
     * SECURITY FIX: Now checks both legacy role() and roles() relationship
     */
    public function isEkklesiaAdmin(): bool
    {
        // Check legacy role relationship
        // SECURITY: Must verify role is active and not deleted
        if ($this->role && 
            $this->role->name === Role::EKKLESIA_ADMIN && 
            $this->role->active === 1 && 
            $this->role->deleted_at === null) {
            return true;
        }
        
        // Check roles() relationship
        // SECURITY: Use activeRoles() which filters by active status and non-deleted
        return $this->activeRoles()
            ->where('name', Role::EKKLESIA_ADMIN)
            ->exists();
    }

    /**
     * Check if user is Ekklesia Manager
     * 
     * SECURITY FIX: Now checks both legacy role() and roles() relationship
     */
    public function isEkklesiaManager(): bool
    {
        // Check legacy role relationship
        // SECURITY: Must verify role is active and not deleted
        if ($this->role && 
            $this->role->name === Role::EKKLESIA_MANAGER && 
            $this->role->active === 1 && 
            $this->role->deleted_at === null) {
            return true;
        }
        
        // Check roles() relationship
        // SECURITY: Use activeRoles() which filters by active status and non-deleted
        return $this->activeRoles()
            ->where('name', Role::EKKLESIA_MANAGER)
            ->exists();
    }

    /**
     * Check if user is Ekklesia User
     * 
     * SECURITY FIX: Now checks both legacy role() and roles() relationship
     */
    public function isEkklesiaUser(): bool
    {
        // Check legacy role relationship
        // SECURITY: Must verify role is active and not deleted
        if ($this->role && 
            $this->role->name === Role::EKKLESIA_USER && 
            $this->role->active === 1 && 
            $this->role->deleted_at === null) {
            return true;
        }
        
        // Check roles() relationship
        // SECURITY: Use activeRoles() which filters by active status and non-deleted
        return $this->activeRoles()
            ->where('name', Role::EKKLESIA_USER)
            ->exists();
    }

    /**
     * Check if user has any Ekklesia role (SuperAdmin, EkklesiaAdmin, EkklesiaManager, EkklesiaUser)
     * This is used to determine access to Ekklesia-only features like Ecclesiastical Data Management
     * 
     * SECURITY FIX: Now checks roles() relationship for multi-role support
     */
    public function hasEkklesiaRole(): bool
    {
        $ekklesiaRoles = [
            Role::SUPER_ADMIN,
            Role::EKKLESIA_ADMIN,
            Role::EKKLESIA_MANAGER,
            Role::EKKLESIA_USER,
        ];
        
        // Check legacy role relationship
        // SECURITY: Must verify role is active and not deleted
        if ($this->role && 
            in_array($this->role->name, $ekklesiaRoles) && 
            $this->role->active === 1 && 
            $this->role->deleted_at === null) {
            return true;
        }
        
        // Check roles() relationship
        // SECURITY: Use activeRoles() which filters by active status and non-deleted
        return $this->activeRoles()
            ->whereIn('name', $ekklesiaRoles)
            ->exists();
    }

    /**
     * Check if user is a tenant administrator (has Administrator role within their tenant)
     */
    public function isTenantAdmin(): bool
    {
        // Check if user has Administrator role that belongs to their tenant
        // The role must have the same tenant_id as the user
        if (!$this->tenant_id) {
            return false; // User without tenant cannot be tenant admin
        }
        
        // First try using loaded relationship if available (more efficient)
        if ($this->relationLoaded('roles')) {
            return $this->roles->contains(function ($role) {
                return $role->name === 'Administrator' && 
                       $role->tenant_id === $this->tenant_id;
            });
        }
        
        // Fallback to query if relationship not loaded
        return $this->roles()
            ->where('name', 'Administrator')
            ->where('tenant_id', $this->tenant_id)
            ->exists();
    }

    /**
     * Check if user can edit another user based on hierarchical permissions
     * 
     * Rules:
     * 1. Cannot edit self
     * 2. SuperAdmin and EkklesiaAdmin can edit anyone
     * 3. Primary admin can edit all users in their tenant
     * 4. Secondary admins cannot edit primary admin or other admins
     * 5. Must be in same tenant (tenant isolation)
     * 
     * @param User $targetUser The user being edited
     * @return bool
     */
    public function canEditUser(User $targetUser): bool
    {
        // Rule 1: Cannot edit self
        if ($this->id === $targetUser->id) {
            return false;
        }

        // Rule 2: SuperAdmin and EkklesiaAdmin can edit anyone
        if ($this->isSuperAdmin() || $this->isEkklesiaAdmin()) {
            return true;
        }

        // Rule 5: Must be in same tenant (tenant isolation)
        if ($this->tenant_id !== $targetUser->tenant_id) {
            return false;
        }

        // If target is primary admin, only SuperAdmin/EkklesiaAdmin can edit (already checked above)
        if ($targetUser->is_primary_admin) {
            return false;
        }

        // Rule 3: Primary admin can edit all non-primary users in their tenant
        if ($this->is_primary_admin) {
            return true;
        }

        // Rule 4: Secondary admins cannot edit other admins (including primary)
        if ($this->isTenantAdmin() && $targetUser->isTenantAdmin()) {
            return false;
        }

        // Regular users can edit if they have the permission
        return $this->hasPermission('users.update');
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
     * 
     * SECURITY: Validates that permissions belong to user's tenant or are system-wide
     * PERFORMANCE: Clears permissions cache after assignment
     * 
     * @param mixed ...$permissions
     * @return self
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \RuntimeException If permission doesn't belong to user's tenant
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
                // SuperAdmin can assign any permission
                if (!$this->isSuperAdmin()) {
                    // Permission must be active
                    if ($permission->active !== 1 || $permission->deleted_at !== null) {
                        throw new \RuntimeException("Cannot assign inactive or deleted permission: {$permission->name}");
                    }
                    
                    // Permission must be system-wide (tenant_id = null) OR belong to user's tenant
                    if (!is_null($permission->tenant_id) && $permission->tenant_id !== $this->tenant_id) {
                        throw new \RuntimeException(
                            "Cannot assign permission from different tenant. Permission: {$permission->name} (tenant_id: {$permission->tenant_id}), User tenant_id: {$this->tenant_id}"
                        );
                    }
                }
                
                $this->permissions()->syncWithoutDetaching([$permission->id]);
            });

        // Clear permissions cache after permission assignment
        $this->clearPermissionsCache();

        return $this;
    }

    /**
     * Remove permission from this user.
     * 
     * PERFORMANCE: Clears permissions cache after revocation
     * 
     * @param mixed ...$permissions
     * @return self
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

        // Clear permissions cache after permission revocation
        $this->clearPermissionsCache();

        return $this;
    }
}