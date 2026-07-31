<?php

namespace Modules\Authentication\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Modules\Authentication\Models\User;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Http\Requests\SyncTenantUserRolesRequest;
use Modules\Authentication\Http\Requests\StoreUserRequest;
use Modules\Authentication\Http\Requests\UpdateUserRequest;
use Modules\RolesAndPermissions\Services\TenantRoleAssignmentService;

/**
 * UserController - Tenant User Management
 * 
 * This controller handles user management operations within a tenant.
 * Tenant administrators can create, update, delete, and manage users
 * with multiple role assignments.
 * 
 * Features:
 * - Multi-role user management
 * - Tenant isolation (users can only manage users within their tenant)
 * - Permission aggregation from multiple roles
 * - Secure password hashing
 * - Audit logging
 * 
 * @package Modules\Authentication\Http\Controllers
 */
class UserController extends Controller
{
    public function __construct(private TenantRoleAssignmentService $tenantRoleAssignmentService)
    {
    }

    /**
     * Display a listing of users for the authenticated user's tenant.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            // Load roles relationship if not already loaded (needed for isTenantAdmin check)
            if (!$user->relationLoaded('roles')) {
                $user->load('roles');
            }
            
            // Authorization: Users with users.view permission OR tenant administrators can view users
            $hasPermission = $user->hasPermission('users.view');
            $isTenantAdmin = $user->isTenantAdmin();
            $isSuperAdmin = $user->isSuperAdmin();
            $isEkklesiaAdmin = $user->isEkklesiaAdmin();
            
            $canView = $hasPermission || $isTenantAdmin || $isSuperAdmin || $isEkklesiaAdmin;
            
            // Log authorization check for debugging
            Log::debug('User list authorization check', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'tenant_id' => $user->tenant_id,
                'has_permission' => $hasPermission,
                'is_tenant_admin' => $isTenantAdmin,
                'is_super_admin' => $isSuperAdmin,
                'is_ekklesia_admin' => $isEkklesiaAdmin,
                'can_view' => $canView,
                'roles' => $user->roles->pluck('name')->toArray(),
            ]);
            
            if (!$canView) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to view users.',
                ], 403);
            }

            // Build query with tenant isolation
            $query = User::with(['roles', 'tenant'])
                ->where('tenant_id', $user->tenant_id);

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%")
                        ->orWhere('contact_number', 'ilike', "%{$search}%");
                });
            }

            // Filter by role
            if ($request->filled('role_id')) {
                $query->whereHas('roles', function ($q) use ($request) {
                    $q->where('roles.id', $request->input('role_id'));
                });
            }

            // Filter by status
            if ($request->filled('status')) {
                $status = $request->input('status');
                if ($status === 'active') {
                    $query->where('active', 1);
                } elseif ($status === 'inactive') {
                    $query->where('active', 0);
                }
            }

            // Pagination
            $perPage = $request->input('per_page', 15);
            if ($perPage === 'all') {
                $users = $query->get();
                
                // Add authorization flags to each user
                $usersWithAuth = $users->map(function ($targetUser) use ($user) {
                    $userData = $targetUser->toArray();
                    $userData['can_edit'] = $user->canEditUser($targetUser);
                    $userData['is_self'] = $user->id === $targetUser->id;
                    return $userData;
                });
                
                return response()->json([
                    'success' => true,
                    'data' => $usersWithAuth,
                    'meta' => [
                        'total' => $users->count(),
                    ],
                ]);
            }

            $users = $query->paginate($perPage);

            // Add authorization flags to each user
            $usersWithAuth = collect($users->items())->map(function ($targetUser) use ($user) {
                $userData = $targetUser->toArray();
                $userData['can_edit'] = $user->canEditUser($targetUser);
                $userData['is_self'] = $user->id === $targetUser->id;
                return $userData;
            });

            return response()->json([
                'success' => true,
                'data' => $usersWithAuth,
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                    'last_page' => $users->lastPage(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching users: ' . $e->getMessage(), [
                'user_id' => $request->user()->id ?? null,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching users.',
            ], 500);
        }
    }

    /**
     * Display the specified user.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $authUser = $request->user();
            
            // Authorization check: Users with users.view permission OR tenant administrators can view users
            $canView = $authUser->hasPermission('users.view') || 
                      $authUser->isTenantAdmin() ||
                      $authUser->isSuperAdmin() ||
                      $authUser->isEkklesiaAdmin();
            
            if (!$canView) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to view users.',
                ], 403);
            }

            // SuperAdmin/EkklesiaAdmin can view any user, others only their tenant
            if ($authUser->isSuperAdmin() || $authUser->isEkklesiaAdmin()) {
                $user = User::with(['roles.permissions', 'tenant', 'addresses'])->findOrFail($id);
            } else {
                $user = User::with(['roles.permissions', 'tenant', 'addresses'])
                    ->where('tenant_id', $authUser->tenant_id)
                    ->findOrFail($id);
            }

            // Include aggregated permissions
            $allPermissions = $user->getAllPermissions();

            // Check if current user can edit this user
            $canEdit = $authUser->canEditUser($user);
            $isSelf = $authUser->id === $user->id;

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $user,
                    'all_permissions' => $allPermissions,
                    'can_edit' => $canEdit,
                    'is_self' => $isSelf,
                    'edit_restriction_reason' => !$canEdit ? $this->getEditRestrictionReason($authUser, $user) : null,
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching user details: ' . $e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching user details.',
            ], 500);
        }
    }

    /**
     * Store a newly created user in storage.
     *
     * @param StoreUserRequest $request
     * @return JsonResponse
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            $authUser = $request->user();
            
            // Load roles relationship if not already loaded (needed for isTenantAdmin check)
            if (!$authUser->relationLoaded('roles')) {
                $authUser->load('roles');
            }
            
            // Authorization: Users with users.create permission OR tenant administrators can create users
            // Tenant administrators should be able to manage users within their tenant
            $hasPermission = $authUser->hasPermission('users.create');
            $isTenantAdmin = $authUser->isTenantAdmin();
            $isSuperAdmin = $authUser->isSuperAdmin();
            $isEkklesiaAdmin = $authUser->isEkklesiaAdmin();
            
            $canCreate = $hasPermission || $isTenantAdmin || $isSuperAdmin || $isEkklesiaAdmin;
            
            // Log authorization check for debugging
            Log::debug('User creation authorization check', [
                'user_id' => $authUser->id,
                'user_email' => $authUser->email,
                'tenant_id' => $authUser->tenant_id,
                'has_permission' => $hasPermission,
                'is_tenant_admin' => $isTenantAdmin,
                'is_super_admin' => $isSuperAdmin,
                'is_ekklesia_admin' => $isEkklesiaAdmin,
                'can_create' => $canCreate,
                'roles' => $authUser->roles->pluck('name')->toArray(),
            ]);
            
            if (!$canCreate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to create users.',
                ], 403);
            }

            // Validate that roles belong to the same tenant
            $roleIds = $request->input('role_ids', []);
            $invalidRoles = Role::whereIn('id', $roleIds)
                ->where(function ($query) use ($authUser) {
                    $query->where('tenant_id', '!=', $authUser->tenant_id)
                        ->whereNotNull('tenant_id')
                        ->orWhere('role_type', Role::ROLE_TYPE_PLATFORM);
                })
                ->exists();

            if ($invalidRoles) {
                return response()->json([
                    'success' => false,
                    'message' => 'One or more selected roles do not belong to your tenant.',
                ], 422);
            }

            // Create the user
            $user = User::create([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'password' => Hash::make($request->input('password')),
                'contact_number' => $request->input('contact_number'),
                'tenant_id' => $authUser->tenant_id, // Enforce tenant isolation
                'user_type' => $request->input('user_type', User::USER_TYPE_PRIMARY_CONTACT),
                'active' => $request->input('active', 1),
            ]);

            // Assign roles
            if (!empty($roleIds)) {
                $user->syncRoles($roleIds);
            }

            // Load relationships for response
            $user->load(['roles', 'tenant']);

            DB::commit();

            Log::info('User created successfully', [
                'created_by' => $authUser->id,
                'user_id' => $user->id,
                'tenant_id' => $authUser->tenant_id,
                'roles' => $roleIds,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User created successfully.',
                'data' => $user,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error creating user: ' . $e->getMessage(), [
                'created_by' => $request->user()->id ?? null,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the user.',
            ], 500);
        }
    }

    /**
     * Update the specified user in storage.
     *
     * @param UpdateUserRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            $authUser = $request->user();
            
            // Authorization check: Users with users.update permission OR tenant administrators can update users
            $canUpdate = $authUser->hasPermission('users.update') || 
                        $authUser->isTenantAdmin() ||
                        $authUser->isSuperAdmin() ||
                        $authUser->isEkklesiaAdmin();
            
            if (!$canUpdate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to update users.',
                ], 403);
            }

            // Find user - SuperAdmin/EkklesiaAdmin can access any user, others only their tenant
            if ($authUser->isSuperAdmin() || $authUser->isEkklesiaAdmin()) {
                $user = User::findOrFail($id);
            } else {
                $user = User::where('tenant_id', $authUser->tenant_id)->findOrFail($id);
            }

            // Use comprehensive authorization check
            if (!$authUser->canEditUser($user)) {
                // Determine specific error message based on the restriction
                if ($authUser->id === $user->id) {
                    $message = 'You cannot edit your own account details. Please contact your administrator for assistance.';
                } elseif ($user->is_primary_admin && !$authUser->isSuperAdmin() && !$authUser->isEkklesiaAdmin()) {
                    $message = 'Only Super Admin and Ekklesia Admin can modify the primary administrator account.';
                } elseif (!$authUser->is_primary_admin && $user->isTenantAdmin()) {
                    $message = 'Secondary administrators cannot modify other administrator accounts. Please contact the primary administrator.';
                } elseif ($authUser->tenant_id !== $user->tenant_id) {
                    $message = 'You can only modify users within your own tenant.';
                } else {
                    $message = 'You do not have permission to modify this user account.';
                }

                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 403);
            }

            // Prevent deactivation of primary admin (additional safety check)
            if ($user->is_primary_admin && $request->input('active') == 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'The primary admin account cannot be deactivated. This account is essential for maintaining tenant administrative continuity.',
                ], 403);
            }

            // Update basic info
            $user->name = $request->input('name', $user->name);
            $user->email = $request->input('email', $user->email);
            $user->contact_number = $request->input('contact_number', $user->contact_number);
            $user->active = $request->input('active', $user->active);

            // Update password if provided
            if ($request->filled('password')) {
                $user->password = Hash::make($request->input('password'));
            }

            $user->save();

            // Update roles if provided
            if ($request->has('role_ids')) {
                $roleIds = $request->input('role_ids', []);
                
                // Validate that roles belong to the same tenant
                $invalidRoles = Role::whereIn('id', $roleIds)
                    ->where(function ($query) use ($authUser) {
                        $query->where('tenant_id', '!=', $authUser->tenant_id)
                            ->whereNotNull('tenant_id');
                    })
                    ->exists();

                if ($invalidRoles) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'One or more selected roles do not belong to your tenant.',
                    ], 422);
                }

                $user->syncRoles($roleIds);
            }

            // Load relationships for response
            $user->load(['roles', 'tenant']);

            DB::commit();

            Log::info('User updated successfully', [
                'updated_by' => $authUser->id,
                'user_id' => $user->id,
                'tenant_id' => $authUser->tenant_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully.',
                'data' => $user,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error updating user: ' . $e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the user.',
            ], 500);
        }
    }

    /**
     * Remove the specified user from storage (soft delete).
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            $authUser = $request->user();
            
            // Authorization check: Users with users.delete permission OR tenant administrators can delete users
            $canDelete = $authUser->hasPermission('users.delete') || 
                        $authUser->isTenantAdmin() ||
                        $authUser->isSuperAdmin() ||
                        $authUser->isEkklesiaAdmin();
            
            if (!$canDelete) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to delete users.',
                ], 403);
            }

            // Find user with tenant isolation
            $user = User::where('tenant_id', $authUser->tenant_id)->findOrFail($id);

            // Prevent deletion of system accounts
            if ($user->isSuperAdmin() || $user->isEkklesiaAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete system administrator accounts.',
                ], 403);
            }

            // Prevent tenant users from deleting the primary admin account
            if ($user->is_primary_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'The primary admin account cannot be deleted. This account is essential for maintaining tenant administrative continuity and system integrity.',
                ], 403);
            }

            // Prevent self-deletion
            if ($user->id === $authUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete your own account.',
                ], 403);
            }

            $user->delete(); // Soft delete

            DB::commit();

            Log::info('User deleted successfully', [
                'deleted_by' => $authUser->id,
                'user_id' => $user->id,
                'tenant_id' => $authUser->tenant_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully.',
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error deleting user: ' . $e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while deleting the user.',
            ], 500);
        }
    }

    /**
     * Get all permissions for a specific user (aggregated from all roles).
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function permissions(Request $request, int $id): JsonResponse
    {
        try {
            $authUser = $request->user();
            
            // Authorization check: Users with users.view permission OR tenant administrators can view user permissions
            $canView = $authUser->hasPermission('users.view') || 
                      $authUser->isTenantAdmin() ||
                      $authUser->isSuperAdmin() ||
                      $authUser->isEkklesiaAdmin();
            
            if (!$canView) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to view user permissions.',
                ], 403);
            }
            
            // Find user with tenant isolation
            $user = User::where('tenant_id', $authUser->tenant_id)->findOrFail($id);

            // Get all permissions
            $allPermissions = $user->getAllPermissions();

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'roles' => $user->roles,
                    'permissions' => $allPermissions,
                    'total_permissions' => $allPermissions->count(),
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching user permissions: ' . $e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching user permissions.',
            ], 500);
        }
    }

    /**
     * Get roles for a specific user inside current tenant scope.
     */
    public function getRoles(Request $request, int $id): JsonResponse
    {
        try {
            $authUser = $request->user();
            $canView = $authUser->hasPermission('users.view') ||
                $authUser->isTenantAdmin() ||
                $authUser->isSuperAdmin() ||
                $authUser->isEkklesiaAdmin();

            if (!$canView) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to view user roles.',
                ], 403);
            }

            $user = $this->tenantRoleAssignmentService->getUserRoles($authUser, $id);

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $user->id,
                    'roles' => $user->roles,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 404, 422], true) ? $e->getCode() : 422;
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            Log::error('Error fetching user roles: ' . $e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching user roles.',
            ], 500);
        }
    }

    /**
     * Assign roles to a user.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function assignRoles(Request $request, int $id): JsonResponse
    {
        try {
            $authUser = $request->user();
            
            // Authorization check: Requires both users.update and roles.assign permissions OR tenant admin
            $canAssign = ($authUser->hasPermission('users.update') && $authUser->hasPermission('roles.assign')) ||
                        $authUser->isTenantAdmin() ||
                        $authUser->isSuperAdmin() ||
                        $authUser->isEkklesiaAdmin();
            
            if (!$canAssign) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to assign roles.',
                ], 403);
            }

            // Validate request
            $request->validate([
                'role_ids' => 'required|array|min:1',
                'role_ids.*' => 'required|integer|exists:roles,id',
            ]);

            $roleIds = $request->input('role_ids');
            $targetUser = User::findOrFail($id);

            if ($authUser->tenant_id) {
                // Tenant-context assignments must use strict tenant guardrails.
                $user = $this->tenantRoleAssignmentService->syncUserRoles($authUser, $targetUser, $roleIds);
            } else {
                // Platform-context fallback for super/ekklesia operators.
                $roles = Role::whereIn('id', $roleIds)
                    ->where('active', 1)
                    ->whereNull('deleted_at')
                    ->get();

                if ($roles->count() !== count($roleIds)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'One or more selected roles are invalid.',
                    ], 422);
                }

                $targetUser->syncRoles($roleIds);
                $primaryRoleId = $roles->sortBy('level')->pluck('id')->first();
                if ($primaryRoleId) {
                    $targetUser->role_id = $primaryRoleId;
                    $targetUser->save();
                }
                $user = $targetUser->fresh('roles');
            }

            Log::info('Roles assigned to user', [
                'assigned_by' => $authUser->id,
                'user_id' => $user->id,
                'role_ids' => $roleIds,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Roles assigned successfully.',
                'data' => [
                    'user' => $user,
                    'roles' => $user->roles,
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 404, 422], true) ? $e->getCode() : 422;
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            Log::error('Error assigning roles: ' . $e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while assigning roles.',
            ], 500);
        }
    }

    /**
     * Tenant endpoint: sync roles for a tenant user with guardrails.
     */
    public function syncTenantRoles(SyncTenantUserRolesRequest $request, int $id): JsonResponse
    {
        try {
            $authUser = $request->user();
            $roleIds = $request->validated()['role_ids'];
            $user = User::findOrFail($id);
            $user = $this->tenantRoleAssignmentService->syncUserRoles($authUser, $user, $roleIds);

            return response()->json([
                'success' => true,
                'message' => 'User roles updated successfully.',
                'data' => [
                    'user_id' => $user->id,
                    'roles' => $user->roles,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 404, 422], true) ? $e->getCode() : 422;
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            Log::error('Error syncing tenant user roles: ' . $e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating user roles.',
            ], 500);
        }
    }

    /**
     * Update user status (active/inactive).
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            $authUser = $request->user();
            
            // Authorization check: Users with users.update permission OR tenant administrators can update user status
            $canUpdate = $authUser->hasPermission('users.update') || 
                        $authUser->isTenantAdmin() ||
                        $authUser->isSuperAdmin() ||
                        $authUser->isEkklesiaAdmin();
            
            if (!$canUpdate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to update users.',
                ], 403);
            }

            // Validate request
            $request->validate([
                'active' => 'required|integer|in:0,1',
            ]);

            // Find user with tenant isolation
            $user = User::where('tenant_id', $authUser->tenant_id)->findOrFail($id);

            // Prevent deactivation of primary admin
            if ($user->is_primary_admin && $request->input('active') === 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'The primary admin account cannot be deactivated. This account is essential for maintaining tenant administrative continuity.',
                ], 403);
            }

            // Prevent self-deactivation
            if ($user->id === $authUser->id && $request->input('active') === 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot deactivate your own account.',
                ], 403);
            }

            // Update status
            $user->active = $request->input('active');
            $user->save();

            DB::commit();

            Log::info('User status updated', [
                'updated_by' => $authUser->id,
                'user_id' => $user->id,
                'new_status' => $user->active,
                'tenant_id' => $authUser->tenant_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User status updated successfully.',
                'data' => $user,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error updating user status: ' . $e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the user status.',
            ], 500);
        }
    }

    /**
     * Get user statistics for the current tenant.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $authUser = $request->user();
            
            // Load roles relationship if not already loaded (needed for isTenantAdmin check)
            if (!$authUser->relationLoaded('roles')) {
                $authUser->load('roles');
            }
            
            // Authorization: Users with users.view permission OR tenant administrators can view statistics
            $hasPermission = $authUser->hasPermission('users.view');
            $isTenantAdmin = $authUser->isTenantAdmin();
            $isSuperAdmin = $authUser->isSuperAdmin();
            $isEkklesiaAdmin = $authUser->isEkklesiaAdmin();
            
            $canView = $hasPermission || $isTenantAdmin || $isSuperAdmin || $isEkklesiaAdmin;
            
            if (!$canView) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to view user statistics.',
                ], 403);
            }

            // Get statistics for the user's tenant (tenant isolation)
            // Build base query for tenant
            $baseQuery = User::where('tenant_id', $authUser->tenant_id);
            
            // Get total count
            $total = (clone $baseQuery)->count();
            
            // Get active users count
            $active = (clone $baseQuery)->where('active', 1)->count();
            
            // Get inactive users count
            $inactive = (clone $baseQuery)->where('active', 0)->count();

            Log::debug('User statistics retrieved', [
                'user_id' => $authUser->id,
                'tenant_id' => $authUser->tenant_id,
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'active' => $active,
                    'inactive' => $inactive,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching user statistics: ' . $e->getMessage(), [
                'user_id' => $request->user()->id ?? null,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching user statistics.',
            ], 500);
        }
    }

    /**
     * Get the reason why a user cannot edit another user.
     * 
     * @param User $authUser The authenticated user
     * @param User $targetUser The user being edited
     * @return string|null The restriction reason or null if can edit
     */
    private function getEditRestrictionReason(User $authUser, User $targetUser): ?string
    {
        if ($authUser->id === $targetUser->id) {
            return 'You cannot edit your own account details. Please contact your administrator for assistance.';
        }

        if ($authUser->isSuperAdmin() || $authUser->isEkklesiaAdmin()) {
            return null; // SuperAdmin and EkklesiaAdmin can edit anyone
        }

        if ($authUser->tenant_id !== $targetUser->tenant_id) {
            return 'You can only modify users within your own tenant.';
        }

        if ($targetUser->is_primary_admin) {
            return 'Only Super Admin and Ekklesia Admin can modify the primary administrator account.';
        }

        if (!$authUser->is_primary_admin && $targetUser->isTenantAdmin()) {
            return 'Secondary administrators cannot modify other administrator accounts. Please contact the primary administrator.';
        }

        if (!$authUser->hasPermission('users.update')) {
            return 'You do not have permission to modify user accounts.';
        }

        return null;
    }
}

