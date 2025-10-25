<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Authentication\Models\User;
use Modules\Authentication\Models\Role;

class UsersController extends Controller
{
    /**
     * List users with tenant-based filtering.
     * 
     * - SuperAdmin and EkklesiaAdmin can see all users
     * - Tenant users can only see users from their own tenant
     * 
     * @route GET /api/users
     */
    public function index(Request $request): JsonResponse
    {
        try {
            if (!auth()->check()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $user = auth()->user();
            $query = User::with(['role', 'tenant']);

            // TENANT ISOLATION: Apply tenant-based filtering
            if ($user->isSuperAdmin() || $user->isEkklesiaAdmin()) {
                // SuperAdmin and EkklesiaAdmin can see all users
                Log::info('Admin viewing all users', [
                    'user_id' => $user->id,
                    'role' => $user->role->name ?? 'Unknown'
                ]);
            } else {
                // Tenant users can ONLY see users from their own tenant
                if (!$user->tenant_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No tenant associated with your account',
                    ], 403);
                }

                $query->where('tenant_id', $user->tenant_id);
                
                Log::info('Tenant user viewing own tenant users', [
                    'user_id' => $user->id,
                    'tenant_id' => $user->tenant_id
                ]);
            }

            // Apply filters
            if ($request->has('active')) {
                $query->where('active', $request->active);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('contact_number', 'like', "%{$search}%");
                });
            }

            if ($request->has('role_id')) {
                $query->where('role_id', $request->role_id);
            }

            // Filter by tenant (only for SuperAdmin/EkklesiaAdmin)
            if ($request->has('tenant_id') && ($user->isSuperAdmin() || $user->isEkklesiaAdmin())) {
                $query->where('tenant_id', $request->tenant_id);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);

            if ($perPage === 'all') {
                $users = $query->get();
                return response()->json([
                    'success' => true,
                    'data' => $users,
                    'total' => $users->count(),
                ]);
            } else {
                $users = $query->paginate($perPage);
                return response()->json([
                    'success' => true,
                    'data' => $users->items(),
                    'pagination' => [
                        'current_page' => $users->currentPage(),
                        'last_page' => $users->lastPage(),
                        'per_page' => $users->perPage(),
                        'total' => $users->total(),
                        'from' => $users->firstItem(),
                        'to' => $users->lastItem(),
                    ],
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error fetching users', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching users',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    /**
     * Get a specific user by ID (with tenant ownership verification).
     * 
     * @route GET /api/users/{id}
     */
    public function show($id): JsonResponse
    {
        try {
            if (!auth()->check()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $currentUser = auth()->user();
            $user = User::with(['role', 'tenant', 'addresses'])->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // TENANT ISOLATION: Verify access
            if (!$currentUser->isSuperAdmin() && !$currentUser->isEkklesiaAdmin()) {
                if ($user->tenant_id !== $currentUser->tenant_id) {
                    Log::warning('Unauthorized user access attempt', [
                        'requesting_user_id' => $currentUser->id,
                        'requesting_tenant_id' => $currentUser->tenant_id,
                        'target_user_id' => $user->id,
                        'target_tenant_id' => $user->tenant_id,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized access to user from different tenant',
                    ], 403);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $user,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching user',
            ], 500);
        }
    }

    /**
     * Get statistics about users (tenant-scoped).
     * 
     * @route GET /api/users/statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            if (!auth()->check()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $user = auth()->user();
            $query = User::query();

            // TENANT ISOLATION: Apply filtering
            if (!$user->isSuperAdmin() && !$user->isEkklesiaAdmin()) {
                if (!$user->tenant_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No tenant associated with your account',
                    ], 403);
                }
                $query->where('tenant_id', $user->tenant_id);
            }

            $total = $query->count();
            $active = (clone $query)->where('active', 1)->count();
            $inactive = (clone $query)->where('active', 0)->count();
            $byRole = (clone $query)->select('role_id')
                ->selectRaw('count(*) as count')
                ->groupBy('role_id')
                ->with('role:id,name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'active' => $active,
                    'inactive' => $inactive,
                    'by_role' => $byRole,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user statistics', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching statistics',
            ], 500);
        }
    }

    /**
     * Update user active status (with tenant verification).
     * 
     * @route PATCH /api/users/{id}/status
     */
    public function updateStatus(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'active' => 'required|integer|in:0,1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $currentUser = auth()->user();
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // TENANT ISOLATION: Verify access
            if (!$currentUser->isSuperAdmin() && !$currentUser->isEkklesiaAdmin()) {
                if ($user->tenant_id !== $currentUser->tenant_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized access to user from different tenant',
                    ], 403);
                }
            }

            // Prevent users from deactivating themselves
            if ($user->id === $currentUser->id && $request->active === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot deactivate your own account',
                ], 403);
            }

            $user->active = $request->active;
            $user->save();

            Log::info('User status updated', [
                'user_id' => $user->id,
                'new_status' => $request->active,
                'updated_by' => $currentUser->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User status updated successfully',
                'data' => $user,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating user status', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error updating user status',
            ], 500);
        }
    }
}

