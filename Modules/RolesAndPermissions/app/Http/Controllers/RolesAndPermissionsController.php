<?php

namespace Modules\RolesAndPermissions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Modules\Authentication\Models\Role;
use Illuminate\Support\Facades\Log;

class RolesAndPermissionsController extends Controller
{
    /**
     * List all roles (with pagination and filters).
     * - SuperAdmin sees all roles (global + all tenant roles)
     * - EkklesiaAdmin/Manager sees only their tenant's roles + global roles
     */
    public function index(Request $request): JsonResponse
    {
        try {
            if (!auth()->check()) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $user = auth()->user();
            $query = Role::query();

            // Apply role-based filtering
            // CRITICAL SECURITY: Enforce strict tenant isolation
            if ($user->isSuperAdmin()) {
                // SuperAdmin sees ALL roles (global + all tenants)
                // No filter needed - full system access
                Log::debug('Roles query: SuperAdmin - viewing all roles', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                ]);
            } else if ($user->isEkklesiaAdmin() || $user->isEkklesiaManager()) {
                // System-level Ekklesia roles see global roles + all tenant-specific roles
                $query->where(function ($q) use ($user) {
                    $q->whereNull('tenant_id') // Global system roles
                      ->orWhereNotNull('tenant_id'); // All tenant roles (for management purposes)
                });
                
                Log::debug('Roles query: Ekklesia Admin/Manager - viewing global + tenant roles', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                ]);
            } else if ($user->tenant_id) {
                // TENANT ADMINISTRATORS AND USERS - STRICT ISOLATION
                // Can ONLY see roles belonging to their specific tenant
                // CANNOT see global roles or other tenants' roles
                $query->where('tenant_id', $user->tenant_id);
                
                Log::info('Roles query: Tenant user - strict isolation applied', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'tenant_id' => $user->tenant_id,
                    'role_name' => $user->role->name ?? 'Unknown',
                ]);
            } else {
                // Users without tenant (shouldn't exist in normal operation)
                // Deny access for security
                Log::warning('Roles query: User without tenant attempted access', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied: Invalid user tenant association',
                ], 403);
            }

            // Apply additional filters
            if ($request->has('active')) {
                $query->where('active', $request->active);
            }

            if ($request->has('tenant_id')) {
                if ($user->isSuperAdmin()) {
                    $query->where('tenant_id', $request->tenant_id);
                }
            }

            if ($request->has('is_custom')) {
                $query->where('is_custom', $request->is_custom);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            
            // Handle 'all' case to return all records without pagination
            if ($perPage === 'all') {
                $roles = $query->with('tenant')
                    ->orderBy('level')
                    ->orderBy('created_at', 'desc')
                    ->get();
                
                return response()->json([
                    'success' => true,
                    'data' => $roles,
                    'total' => $roles->count(),
                ]);
            }
            
            $roles = $query->with('tenant')
                ->orderBy('level')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $roles->items(),
                'total' => $roles->total(),
                'pagination' => [
                    'current_page' => $roles->currentPage(),
                    'last_page' => $roles->lastPage(),
                    'per_page' => $roles->perPage(),
                    'from' => $roles->firstItem(),
                    'to' => $roles->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching roles: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error fetching roles',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a specific role.
     */
    public function show($id): JsonResponse
    {
        try {
            if (!auth()->check()) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $user = auth()->user();
            $role = Role::with(['tenant', 'users'])->findOrFail($id);

            // Check authorization
            if (!$this->canViewRole($role)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            return response()->json([
                'role' => $role,
                'stats' => [
                    'total_users' => $role->users()->count(),
                    'active_users' => $role->users()->where('active', 1)->count(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching role: ' . $e->getMessage());
            return response()->json([
                'message' => 'Role not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Create a new role.
     * - SuperAdmin can create global roles or tenant-specific roles
     * - EkklesiaAdmin can create tenant-specific roles for their tenant only
     */
    public function store(Request $request): JsonResponse
    {
        try {
            if (!$this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = auth()->user();

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:roles,name',
                'description' => 'nullable|string',
                'level' => 'required|integer|min:1|max:10',
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
                // Non-SuperAdmins can only create custom roles for their tenant
                $data['tenant_id'] = $user->tenant_id;
                $data['is_custom'] = true;

                if (!$user->tenant_id) {
                    return response()->json([
                        'message' => 'You must belong to a tenant to create roles',
                    ], 403);
                }
            } else {
                // SuperAdmin creating a role
                $data['is_custom'] = $request->get('is_custom', false);
            }

            // Set default level if not a custom role
            if (empty($data['is_custom'])) {
                $data['level'] = $data['level'] ?? 5; // Default custom level
            }

            $role = Role::create($data);

            Log::info('Role created', ['role_id' => $role->id, 'created_by' => auth()->id()]);

            return response()->json([
                'success' => true,
                'message' => 'Role created successfully',
                'data' => [
                    'role' => $role,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating role: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error creating role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an existing role.
     * - SuperAdmin can update any role
     * - EkklesiaAdmin can update only custom roles for their tenant
     * - System roles (SuperAdmin, EkklesiaAdmin, etc.) cannot be modified
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            if (!$this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = auth()->user();
            $role = Role::findOrFail($id);

            // Check if role can be modified
            if ($role->isGlobal() && !$user->isSuperAdmin()) {
                return response()->json([
                    'message' => 'Only SuperAdmin can modify global roles',
                ], 403);
            }

            if ($role->tenant_id && $role->tenant_id !== $user->tenant_id && !$user->isSuperAdmin()) {
                return response()->json([
                    'message' => 'You can only modify roles for your tenant',
                ], 403);
            }

            // Prevent modification of system roles
            if (!$role->isCustom()) {
                return response()->json([
                    'message' => 'System roles cannot be modified. Create a custom role instead.',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255|unique:roles,name,' . $id,
                'description' => 'nullable|string',
                'level' => 'sometimes|required|integer|min:1|max:10',
                'active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $role->update($request->only(['name', 'description', 'level', 'active']));

            Log::info('Role updated', ['role_id' => $role->id, 'updated_by' => auth()->id()]);

            return response()->json([
                'success' => true,
                'message' => 'Role updated successfully',
                'data' => [
                    'role' => $role,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating role: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error updating role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a role (soft delete).
     * - Only custom roles can be deleted
     * - System roles are protected
     */
    public function destroy($id): JsonResponse
    {
        try {
            if (!$this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = auth()->user();
            $role = Role::findOrFail($id);

            // Prevent deletion of system roles
            if (!$role->isCustom()) {
                return response()->json([
                    'message' => 'System roles cannot be deleted',
                ], 403);
            }

            // Check authorization
            if (!$user->isSuperAdmin() && $role->tenant_id !== $user->tenant_id) {
                return response()->json([
                    'message' => 'You can only delete roles for your tenant',
                ], 403);
            }

            // Check if role has users
            if ($role->users()->count() > 0) {
                return response()->json([
                    'message' => 'Cannot delete role with assigned users. Please reassign users first.',
                ], 422);
            }

            $role->delete();

            Log::warning('Role deleted', ['role_id' => $id, 'deleted_by' => auth()->id()]);

            return response()->json([
                'message' => 'Role deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting role: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error deleting role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted role.
     */
    public function restore($id): JsonResponse
    {
        try {
            if (!$this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $role = Role::withTrashed()->findOrFail($id);
            $role->restore();

            Log::info('Role restored', ['role_id' => $id, 'restored_by' => auth()->id()]);

            return response()->json([
                'message' => 'Role restored successfully',
                'role' => $role,
            ]);
        } catch (\Exception $e) {
            Log::error('Error restoring role: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error restoring role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activate a role.
     */
    public function activate($id): JsonResponse
    {
        try {
            if (!$this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $role = Role::findOrFail($id);

            // Only custom roles can be activated/deactivated
            if (!$role->isCustom() && !auth()->user()->isSuperAdmin()) {
                return response()->json([
                    'message' => 'Only SuperAdmin can activate/deactivate system roles',
                ], 403);
            }

            $role->activate();

            Log::info('Role activated', ['role_id' => $id, 'activated_by' => auth()->id()]);

            return response()->json([
                'message' => 'Role activated successfully',
                'role' => $role,
            ]);
        } catch (\Exception $e) {
            Log::error('Error activating role: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error activating role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Deactivate a role.
     */
    public function deactivate($id): JsonResponse
    {
        try {
            if (!$this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $role = Role::findOrFail($id);

            // Only custom roles can be activated/deactivated
            if (!$role->isCustom() && !auth()->user()->isSuperAdmin()) {
                return response()->json([
                    'message' => 'Only SuperAdmin can activate/deactivate system roles',
                ], 403);
            }

            $role->deactivate();

            Log::info('Role deactivated', ['role_id' => $id, 'deactivated_by' => auth()->id()]);

            return response()->json([
                'message' => 'Role deactivated successfully',
                'role' => $role,
            ]);
        } catch (\Exception $e) {
            Log::error('Error deactivating role: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error deactivating role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if current user can manage roles.
     */
    /**
     * SECURITY: Check if user can manage roles (create, update, delete)
     * - SuperAdmin: Can manage all roles
     * - EkklesiaAdmin/Manager: Can manage all roles
     * - Tenant Administrator: Can manage roles within their tenant only
     * - Regular Tenant Users: Cannot manage roles
     */
    private function canManageRoles(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        $user = auth()->user();
        
        // SuperAdmin, EkklesiaAdmin, and EkklesiaManager can manage ALL roles
        if ($user->isSuperAdmin() || $user->isEkklesiaAdmin() || $user->isEkklesiaManager()) {
            return true;
        }
        
        // Tenant Administrators can manage roles within their tenant
        // Load role relationship if not already loaded
        if ($user->tenant_id && $user->role_id) {
            if (!$user->relationLoaded('role')) {
                $user->load('role');
            }
            
            if ($user->role && $user->role->name === 'Administrator') {
                return true;
            }
        }
        
        // All other users cannot manage roles
        return false;
    }

    /**
     * Check if current user can view a specific role.
     */
    /**
     * SECURITY: Check if user can view a specific role
     * - SuperAdmin: Can view all roles
     * - EkklesiaAdmin/Manager: Can view global + all tenant roles
     * - Tenant Users: Can ONLY view their tenant's roles
     */
    private function canViewRole(Role $role): bool
    {
        $user = auth()->user();

        // SuperAdmin can view all roles
        if ($user->isSuperAdmin()) {
            return true;
        }

        // System-level Ekklesia roles can view global roles + all tenant roles
        if ($user->isEkklesiaAdmin() || $user->isEkklesiaManager()) {
            return true; // Full access to view all roles
        }

        // CRITICAL SECURITY: Tenant users can ONLY view their own tenant's roles
        // They CANNOT view global roles or other tenants' roles
        if ($user->tenant_id) {
            $canView = $role->tenant_id === $user->tenant_id;
            
            if (!$canView) {
                Log::warning('Tenant user attempted to view unauthorized role', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'user_tenant_id' => $user->tenant_id,
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'role_tenant_id' => $role->tenant_id,
                ]);
            }
            
            return $canView;
        }

        // Users without tenant are denied (shouldn't happen in normal operation)
        Log::warning('User without tenant attempted to view role', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
        
        return false;
    }
}
