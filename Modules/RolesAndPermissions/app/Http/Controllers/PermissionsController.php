<?php

namespace Modules\RolesAndPermissions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Services\PermissionAuditService;
use Illuminate\Support\Facades\Log;

class PermissionsController extends Controller
{
    protected PermissionAuditService $auditService;

    public function __construct(PermissionAuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * List all permissions (with pagination and filters).
     */
    public function index(Request $request): JsonResponse
    {
        try {
            if (!auth()->check()) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $user = auth()->user();
            $query = Permission::query();

            // Apply role-based filtering
            // CRITICAL SECURITY: Enforce strict tenant isolation for permissions
            if ($user->isSuperAdmin()) {
                // SuperAdmin sees ALL permissions (system + all custom from all tenants)
                // No filter needed - full system access
                Log::debug('Permissions query: SuperAdmin - viewing all permissions', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                ]);
            } else if ($user->isEkklesiaAdmin() || $user->isEkklesiaManager()) {
                // System-level Ekklesia roles see all permissions EXCEPT "Tenants" and "Pope" modules
                // CRITICAL SECURITY: "Tenants" and "Pope" modules are SuperAdmin only
                $query->where(function ($q) {
                    $q->where('module', '!=', 'Tenants')
                      ->where('module', '!=', 'Pope');
                });
                
                Log::debug('Permissions query: Ekklesia Admin/Manager - viewing all permissions (Tenants and Pope modules excluded)', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                ]);
            } else if ($user->tenant_id) {
                // TENANT ADMINISTRATORS AND USERS - STRICT ISOLATION
                // Can see:
                // 1. System permissions (for assigning to roles) - tenant_id = null AND is_custom = false
                //    EXCEPT "Tenants" and "Pope" module permissions (SuperAdmin only)
                // 2. Their own tenant's custom permissions ONLY
                // Cannot see other tenants' custom permissions
                $query->where(function ($q) use ($user) {
                    $q->where(function ($subQ) {
                        // System permissions (available to all tenants)
                        // CRITICAL SECURITY: Exclude "Tenants" and "Pope" modules - SuperAdmin only
                        $subQ->whereNull('tenant_id')
                             ->where('is_custom', false)
                             ->where('module', '!=', 'Tenants')
                             ->where('module', '!=', 'Pope');
                    })
                    ->orWhere(function ($subQ) use ($user) {
                        // Their tenant's custom permissions ONLY
                        $subQ->where('tenant_id', $user->tenant_id)
                             ->where('is_custom', true);
                    });
                });
                
                Log::info('Permissions query: Tenant user - strict isolation applied (Tenants and Pope modules excluded)', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'tenant_id' => $user->tenant_id,
                    'role_name' => $user->role->name ?? 'Unknown',
                ]);
            } else {
                // Users without tenant (shouldn't exist in normal operation)
                // Deny access for security
                Log::warning('Permissions query: User without tenant attempted access', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied: Invalid user tenant association',
                ], 403);
            }

            // Apply filters
            if ($request->has('active')) {
                $query->where('active', $request->active);
            }

            if ($request->has('tenant_id') && $user->isSuperAdmin()) {
                $query->where('tenant_id', $request->tenant_id);
            }

            if ($request->has('module')) {
                $query->byModule($request->module);
            }

            if ($request->has('category')) {
                $query->byCategory($request->category);
            }

            if ($request->has('is_custom')) {
                $query->where('is_custom', $request->is_custom);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('display_name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            $perPage = $request->get('per_page', 15);
            
            // Handle 'all' case to return all records without pagination
            if ($perPage === 'all') {
                $permissions = $query->with('tenant')
                    ->orderBy('module')
                    ->orderBy('category')
                    ->orderBy('name')
                    ->get();
                
                return response()->json([
                    'success' => true,
                    'data' => $permissions,
                    'total' => $permissions->count(),
                ]);
            }
            
            $permissions = $query->with('tenant')
                ->orderBy('module')
                ->orderBy('category')
                ->orderBy('name')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $permissions->items(),
                'total' => $permissions->total(),
                'pagination' => [
                    'current_page' => $permissions->currentPage(),
                    'last_page' => $permissions->lastPage(),
                    'per_page' => $permissions->perPage(),
                    'from' => $permissions->firstItem(),
                    'to' => $permissions->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching permissions: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error fetching permissions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a specific permission.
     */
    public function show($id): JsonResponse
    {
        try {
            if (!auth()->check()) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $permission = Permission::with(['tenant', 'roles', 'users'])->findOrFail($id);

            if (!$this->canViewPermission($permission)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            return response()->json([
                'permission' => $permission,
                'stats' => [
                    'assigned_to_roles' => $permission->roles()->count(),
                    'assigned_to_users' => $permission->users()->count(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching permission: ' . $e->getMessage());
            return response()->json([
                'message' => 'Permission not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Create a new permission.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            if (!$this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = auth()->user();

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:permissions,name',
                'display_name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'module' => 'nullable|string|max:255',
                'category' => 'nullable|string|max:255',
                'tenant_id' => 'nullable|exists:tenants,id',
                'is_custom' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $request->all();

            // Authorization checks
            if (!$user->isSuperAdmin()) {
                // Non-SuperAdmins can only create custom permissions for their tenant
                $data['tenant_id'] = $user->tenant_id;
                $data['is_custom'] = true;

                if (!$user->tenant_id) {
                    return response()->json([
                        'message' => 'You must belong to a tenant to create permissions',
                    ], 403);
                }
            } else {
                $data['is_custom'] = $request->get('is_custom', false);
            }

            $permission = Permission::create($data);

            Log::info('Permission created', ['permission_id' => $permission->id, 'created_by' => auth()->id()]);

            return response()->json([
                'message' => 'Permission created successfully',
                'permission' => $permission,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating permission: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error creating permission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an existing permission.
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            if (!$this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = auth()->user();
            $permission = Permission::findOrFail($id);

            // Check if permission can be modified
            if ($permission->isGlobal() && !$user->isSuperAdmin()) {
                return response()->json([
                    'message' => 'Only SuperAdmin can modify global permissions',
                ], 403);
            }

            if ($permission->tenant_id && $permission->tenant_id !== $user->tenant_id && !$user->isSuperAdmin()) {
                return response()->json([
                    'message' => 'You can only modify permissions for your tenant',
                ], 403);
            }

            // Prevent modification of system permissions
            if (!$permission->isCustom()) {
                return response()->json([
                    'message' => 'System permissions cannot be modified. Create a custom permission instead.',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255|unique:permissions,name,' . $id,
                'display_name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'module' => 'nullable|string|max:255',
                'category' => 'nullable|string|max:255',
                'active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $permission->update($request->only(['name', 'display_name', 'description', 'module', 'category', 'active']));

            Log::info('Permission updated', ['permission_id' => $permission->id, 'updated_by' => auth()->id()]);

            return response()->json([
                'message' => 'Permission updated successfully',
                'permission' => $permission,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating permission: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error updating permission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a permission (soft delete).
     */
    public function destroy($id): JsonResponse
    {
        try {
            if (!$this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = auth()->user();
            $permission = Permission::findOrFail($id);

            // Prevent deletion of system permissions
            if (!$permission->isCustom()) {
                return response()->json([
                    'message' => 'System permissions cannot be deleted',
                ], 403);
            }

            // Check authorization
            if (!$user->isSuperAdmin() && $permission->tenant_id !== $user->tenant_id) {
                return response()->json([
                    'message' => 'You can only delete permissions for your tenant',
                ], 403);
            }

            $permission->delete();

            Log::warning('Permission deleted', ['permission_id' => $id, 'deleted_by' => auth()->id()]);

            return response()->json([
                'message' => 'Permission deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting permission: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error deleting permission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign permission to a role.
     */
    public function assignToRole(Request $request): JsonResponse
    {
        try {
            if (!$this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $validator = Validator::make($request->all(), [
                'permission_id' => 'required|exists:permissions,id',
                'role_id' => 'required|exists:roles,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $permission = Permission::findOrFail($request->permission_id);
            $role = Role::findOrFail($request->role_id);
            $currentUser = auth()->user();

            // CRITICAL SECURITY: Prevent non-SuperAdmin users from assigning "Tenants" and "Pope" module permissions
            if (($permission->module === 'Tenants' || $permission->module === 'Pope') && !$currentUser->isSuperAdmin()) {
                Log::warning('Non-SuperAdmin user attempted to assign restricted module permission to role (SuperAdmin only)', [
                    'user_id' => $currentUser->id,
                    'user_email' => $currentUser->email,
                    'permission_id' => $permission->id,
                    'permission_name' => $permission->name,
                    'permission_module' => $permission->module,
                    'role_id' => $role->id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Tenants and Pope module permissions can only be assigned by Super Administrators',
                ], 403);
            }

            // SECURITY: Validate tenant isolation - permission and role must belong to same tenant
            // System permissions (tenant_id = null) can be assigned to any role
            // Tenant-specific permissions can only be assigned to roles from the same tenant
            if (!is_null($permission->tenant_id)) {
                if (is_null($role->tenant_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot assign tenant-specific permission to global role',
                    ], 422);
                }
                if ($permission->tenant_id !== $role->tenant_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot assign permission from different tenant to role',
                    ], 422);
                }
            }

            $permission->assignToRole($role);

            // AUDIT: Log permission assignment
            $this->auditService->logPermissionAssignedToRole($permission, $role, auth()->user());

            Log::info('Permission assigned to role', [
                'permission_id' => $permission->id,
                'role_id' => $role->id,
                'assigned_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Permission assigned to role successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error assigning permission to role: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error assigning permission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove permission from a role.
     */
    public function removeFromRole(Request $request): JsonResponse
    {
        try {
            if (!$this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $validator = Validator::make($request->all(), [
                'permission_id' => 'required|exists:permissions,id',
                'role_id' => 'required|exists:roles,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $permission = Permission::findOrFail($request->permission_id);
            $role = Role::findOrFail($request->role_id);
            $currentUser = auth()->user();

            // CRITICAL SECURITY: Prevent non-SuperAdmin users from removing "Tenants" and "Pope" module permissions
            // This ensures that even if a role has restricted permissions, non-SuperAdmin users cannot modify them
            if (($permission->module === 'Tenants' || $permission->module === 'Pope') && !$currentUser->isSuperAdmin()) {
                Log::warning('Non-SuperAdmin user attempted to remove restricted module permission from role (SuperAdmin only)', [
                    'user_id' => $currentUser->id,
                    'user_email' => $currentUser->email,
                    'permission_id' => $permission->id,
                    'permission_name' => $permission->name,
                    'permission_module' => $permission->module,
                    'role_id' => $role->id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Tenants and Pope module permissions can only be managed by Super Administrators',
                ], 403);
            }

            $permission->removeFromRole($role);

            // AUDIT: Log permission removal from role
            $this->auditService->logPermissionRemovedFromRole($permission, $role, auth()->user());

            Log::info('Permission removed from role', [
                'permission_id' => $permission->id,
                'role_id' => $role->id,
                'removed_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Permission removed from role successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing permission from role: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error removing permission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign permission directly to a user.
     * CRITICAL SECURITY: Prevents non-SuperAdmin users from assigning "Tenants" module permissions
     */
    public function assignToUser(Request $request): JsonResponse
    {
        try {
            if (!$this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $validator = Validator::make($request->all(), [
                'permission_id' => 'required|exists:permissions,id',
                'user_id' => 'required|exists:users,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $permission = Permission::findOrFail($request->permission_id);
            $user = User::findOrFail($request->user_id);
            $currentUser = auth()->user();

            // CRITICAL SECURITY: Prevent non-SuperAdmin users from assigning "Tenants" and "Pope" module permissions
            if (($permission->module === 'Tenants' || $permission->module === 'Pope') && !$currentUser->isSuperAdmin()) {
                Log::warning('Non-SuperAdmin user attempted to assign restricted module permission to user (SuperAdmin only)', [
                    'user_id' => $currentUser->id,
                    'user_email' => $currentUser->email,
                    'permission_id' => $permission->id,
                    'permission_name' => $permission->name,
                    'permission_module' => $permission->module,
                    'target_user_id' => $user->id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Tenants and Pope module permissions can only be assigned by Super Administrators',
                ], 403);
            }

            // SECURITY: Validate tenant isolation - permission and user must belong to same tenant
            // System permissions (tenant_id = null) can be assigned to any user
            // Tenant-specific permissions can only be assigned to users from the same tenant
            if (!is_null($permission->tenant_id)) {
                if (is_null($user->tenant_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot assign tenant-specific permission to user without tenant',
                    ], 422);
                }
                if ($permission->tenant_id !== $user->tenant_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot assign permission from different tenant to user',
                    ], 422);
                }
            }

            $permission->assignToUser($user);

            // AUDIT: Log permission assignment to user
            $this->auditService->logPermissionAssignedToUser($permission, $user, auth()->user());

            Log::info('Permission assigned to user', [
                'permission_id' => $permission->id,
                'user_id' => $user->id,
                'assigned_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Permission assigned to user successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error assigning permission to user: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error assigning permission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove permission from a user.
     */
    public function removeFromUser(Request $request): JsonResponse
    {
        try {
            if (!$this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $validator = Validator::make($request->all(), [
                'permission_id' => 'required|exists:permissions,id',
                'user_id' => 'required|exists:users,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $permission = Permission::findOrFail($request->permission_id);
            $user = User::findOrFail($request->user_id);
            $currentUser = auth()->user();

            // CRITICAL SECURITY: Prevent non-SuperAdmin users from removing "Tenants" and "Pope" module permissions
            // This ensures that even if a user has restricted permissions, non-SuperAdmin users cannot modify them
            if (($permission->module === 'Tenants' || $permission->module === 'Pope') && !$currentUser->isSuperAdmin()) {
                Log::warning('Non-SuperAdmin user attempted to remove restricted module permission from user (SuperAdmin only)', [
                    'user_id' => $currentUser->id,
                    'user_email' => $currentUser->email,
                    'permission_id' => $permission->id,
                    'permission_name' => $permission->name,
                    'permission_module' => $permission->module,
                    'target_user_id' => $user->id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Tenants and Pope module permissions can only be managed by Super Administrators',
                ], 403);
            }

            // SECURITY: Validate tenant isolation - permission and user must belong to same tenant
            // System permissions (tenant_id = null) can be removed from any user
            // Tenant-specific permissions can only be removed from users from the same tenant
            if (!is_null($permission->tenant_id) && !is_null($user->tenant_id)) {
                if ($permission->tenant_id !== $user->tenant_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot remove permission from different tenant from user',
                    ], 422);
                }
            }

            $permission->removeFromUser($user);

            // AUDIT: Log permission removal from user
            $this->auditService->logPermissionRemovedFromUser($permission, $user, auth()->user());

            Log::info('Permission removed from user', [
                'permission_id' => $permission->id,
                'user_id' => $user->id,
                'removed_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Permission removed from user successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing permission from user: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error removing permission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk assign permissions to a role.
     * This replaces all existing permissions for the role with the new set.
     */
    public function bulkAssignToRole(Request $request): JsonResponse
    {
        try {
            if (!$this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $validator = Validator::make($request->all(), [
                'role_id' => 'required|exists:roles,id',
                'permission_ids' => 'required|array',
                'permission_ids.*' => 'required|exists:permissions,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $role = Role::findOrFail($request->role_id);
            
            // Authorization: Check if user can modify this role
            $user = auth()->user();
            if (!$user->isSuperAdmin()) {
                if ($role->isGlobal() || ($role->tenant_id && $role->tenant_id !== $user->tenant_id)) {
                    return response()->json([
                        'message' => 'Unauthorized to modify this role',
                    ], 403);
                }

                // CRITICAL SECURITY: Prevent non-SuperAdmin users from assigning "Tenants" and "Pope" module permissions
                $permissionIds = $request->permission_ids;
                $restrictedPermissionIds = Permission::whereIn('module', ['Tenants', 'Pope'])
                    ->whereIn('id', $permissionIds)
                    ->pluck('id')
                    ->toArray();

                if (!empty($restrictedPermissionIds)) {
                    $restrictedPermissions = Permission::whereIn('id', $restrictedPermissionIds)
                        ->get(['id', 'name', 'module']);
                    
                    Log::warning('Non-SuperAdmin user attempted to assign restricted module permissions (SuperAdmin only)', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'role_id' => $role->id,
                        'restricted_permission_ids' => $restrictedPermissionIds,
                        'restricted_modules' => $restrictedPermissions->pluck('module')->unique()->toArray(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized: Tenants and Pope module permissions can only be assigned by Super Administrators',
                    ], 403);
                }
            }

            // SECURITY: Validate tenant isolation for all permissions before assignment
            $permissionIds = $request->permission_ids;
            $permissions = Permission::whereIn('id', $permissionIds)->get();
            
            foreach ($permissions as $permission) {
                // System permissions (tenant_id = null) can be assigned to any role
                // Tenant-specific permissions can only be assigned to roles from the same tenant
                if (!is_null($permission->tenant_id)) {
                    if (is_null($role->tenant_id)) {
                        return response()->json([
                            'success' => false,
                            'message' => "Cannot assign tenant-specific permission '{$permission->name}' to global role",
                        ], 422);
                    }
                    if ($permission->tenant_id !== $role->tenant_id) {
                        return response()->json([
                            'success' => false,
                            'message' => "Cannot assign permission '{$permission->name}' from different tenant to role",
                        ], 422);
                    }
                }
            }

            // PERFORMANCE & SECURITY: Wrap in transaction for atomicity
            \DB::transaction(function () use ($role, $permissionIds) {
                // Use Laravel's sync method to replace all permissions atomically
                $role->permissions()->sync($permissionIds);
                
                // Clear permission cache for all users with this role
                $role->clearUsersPermissionCache();
            });

            Log::info('Bulk permissions assigned to role', [
                'role_id' => $role->id,
                'permission_count' => count($permissionIds),
                'assigned_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permissions assigned to role successfully',
                'data' => [
                    'role_id' => $role->id,
                    'permissions_count' => count($request->permission_ids),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error bulk assigning permissions to role: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error assigning permissions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get permissions for a specific role.
     * CRITICAL SECURITY: Filters out "Tenants" module permissions for non-SuperAdmin users
     */
    public function getPermissionsForRole($roleId): JsonResponse
    {
        try {
            if (!auth()->check()) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $role = Role::with('permissions')->findOrFail($roleId);

            // Authorization check
            $user = auth()->user();
            if (!$user->isSuperAdmin()) {
                if ($role->isGlobal() || ($role->tenant_id && $role->tenant_id !== $user->tenant_id)) {
                    return response()->json(['message' => 'Unauthorized'], 403);
                }
            }

            // Get permissions for the role
            $permissions = $role->permissions;

            // CRITICAL SECURITY: Filter out "Tenants" and "Pope" module permissions for non-SuperAdmin users
            if (!$user->isSuperAdmin()) {
                $permissions = $permissions->filter(function ($permission) {
                    return $permission->module !== 'Tenants' && $permission->module !== 'Pope';
                })->values(); // Re-index array after filtering
            }

            return response()->json([
                'success' => true,
                'data' => $permissions,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching permissions for role: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching permissions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if current user can manage permissions.
     */
    /**
     * SECURITY: Check if user can manage permissions (create, update, delete)
     * - SuperAdmin: Can manage all permissions
     * - EkklesiaAdmin/Manager: Can manage all permissions
     * - Tenant Administrator: Can manage custom permissions within their tenant only
     * - Regular Tenant Users: Cannot manage permissions
     */
    private function canManagePermissions(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        $user = auth()->user();
        
        // SuperAdmin, EkklesiaAdmin, and EkklesiaManager can manage ALL permissions
        if ($user->isSuperAdmin() || $user->isEkklesiaAdmin() || $user->isEkklesiaManager()) {
            return true;
        }
        
        // Tenant Administrators can manage custom permissions within their tenant
        // Load role relationship if not already loaded
        if ($user->tenant_id && $user->role_id) {
            if (!$user->relationLoaded('role')) {
                $user->load('role');
            }
            
            if ($user->role && $user->role->name === 'Administrator') {
                return true;
            }
        }
        
        // All other users cannot manage permissions
        return false;
    }

    /**
     * SECURITY: Check if user can view a specific permission
     * - SuperAdmin: Can view all permissions
     * - EkklesiaAdmin/Manager: Can view all permissions
     * - Tenant Users: Can view system permissions + their tenant's custom permissions
     */
    private function canViewPermission(Permission $permission): bool
    {
        $user = auth()->user();

        // SuperAdmin can view all permissions
        if ($user->isSuperAdmin()) {
            return true;
        }

        // System-level Ekklesia roles can view all permissions EXCEPT "Tenants" and "Pope" modules
        // CRITICAL SECURITY: "Tenants" and "Pope" modules are SuperAdmin only
        if ($user->isEkklesiaAdmin() || $user->isEkklesiaManager()) {
            if ($permission->module === 'Tenants' || $permission->module === 'Pope') {
                Log::warning('Ekklesia Admin/Manager attempted to view restricted module permission (SuperAdmin only)', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'permission_id' => $permission->id,
                    'permission_name' => $permission->name,
                    'permission_module' => $permission->module,
                ]);
                return false;
            }
            return true;
        }

        // CRITICAL SECURITY: Tenant users can view:
        // 1. System permissions (tenant_id = null AND is_custom = false)
        //    EXCEPT "Tenants" and "Pope" module permissions (SuperAdmin only)
        // 2. Their own tenant's custom permissions ONLY
        if ($user->tenant_id) {
            // System permission (available to all tenants)
            // BUT exclude "Tenants" and "Pope" modules - SuperAdmin only
            if (is_null($permission->tenant_id) && !$permission->is_custom) {
                if ($permission->module === 'Tenants' || $permission->module === 'Pope') {
                    Log::warning('Tenant user attempted to view restricted module permission (SuperAdmin only)', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'user_tenant_id' => $user->tenant_id,
                        'permission_id' => $permission->id,
                        'permission_name' => $permission->name,
                        'permission_module' => $permission->module,
                    ]);
                    return false;
                }
                return true;
            }
            
            // Their tenant's custom permission
            if ($permission->tenant_id === $user->tenant_id && $permission->is_custom) {
                return true;
            }
            
            // Log unauthorized access attempts
            Log::warning('Tenant user attempted to view unauthorized permission', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'user_tenant_id' => $user->tenant_id,
                'permission_id' => $permission->id,
                'permission_name' => $permission->name,
                'permission_tenant_id' => $permission->tenant_id,
                'permission_is_custom' => $permission->is_custom,
                'permission_module' => $permission->module,
            ]);
            
            return false;
        }

        // Users without tenant are denied
        Log::warning('User without tenant attempted to view permission', [
            'user_id' => $user->id,
            'permission_id' => $permission->id,
        ]);
        
        return false;
    }
}

