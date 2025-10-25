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
use Modules\Authentication\Http\Requests\StoreUserRequest;
use Modules\Authentication\Http\Requests\UpdateUserRequest;

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
            
            // Authorization: Only tenant administrators can list users
            if (!$user->hasPermission('users.view')) {
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
                
                return response()->json([
                    'success' => true,
                    'data' => $users,
                    'meta' => [
                        'total' => $users->count(),
                    ],
                ]);
            }

            $users = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $users->items(),
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
            
            // Authorization check
            if (!$authUser->hasPermission('users.view')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to view users.',
                ], 403);
            }

            $user = User::with(['roles.permissions', 'tenant', 'addresses'])
                ->where('tenant_id', $authUser->tenant_id)
                ->findOrFail($id);

            // Include aggregated permissions
            $allPermissions = $user->getAllPermissions();

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $user,
                    'all_permissions' => $allPermissions,
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
            
            // Authorization: Only users with users.create permission can create users
            if (!$authUser->hasPermission('users.create')) {
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
                        ->whereNotNull('tenant_id'); // Allow global/system roles
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
            
            // Authorization check
            if (!$authUser->hasPermission('users.update')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to update users.',
                ], 403);
            }

            // Find user with tenant isolation
            $user = User::where('tenant_id', $authUser->tenant_id)->findOrFail($id);

            // Prevent users from modifying SuperAdmin or EkklesiaAdmin accounts
            if ($user->isSuperAdmin() || $user->isEkklesiaAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot modify system administrator accounts.',
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
            
            // Authorization check
            if (!$authUser->hasPermission('users.delete')) {
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
            
            // Authorization check
            if (!$authUser->hasPermission('users.view')) {
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
     * Assign roles to a user.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function assignRoles(Request $request, int $id): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            $authUser = $request->user();
            
            // Authorization check (requires both users.update and roles.assign permissions)
            if (!$authUser->hasPermission('users.update') || !$authUser->hasPermission('roles.assign')) {
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

            // Find user with tenant isolation
            $user = User::where('tenant_id', $authUser->tenant_id)->findOrFail($id);

            $roleIds = $request->input('role_ids');

            // Validate that roles belong to the same tenant or are global
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

            // Sync roles (replace existing roles)
            $user->syncRoles($roleIds);

            // Load relationships for response
            $user->load(['roles']);

            DB::commit();

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
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            
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
}

