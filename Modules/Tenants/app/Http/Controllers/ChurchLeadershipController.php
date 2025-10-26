<?php

namespace Modules\Tenants\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Tenants\Models\ChurchLeadership;

/**
 * Church Leadership Controller
 * 
 * Manages church leaders (pastors, associate pastors, ministry leaders).
 * Full CRUD operations for tenant administrators.
 */
class ChurchLeadershipController extends Controller
{
    /**
     * Get all church leaders for the tenant.
     * 
     * @route GET /api/church-leadership
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || !$user->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            $query = ChurchLeadership::where('tenant_id', $user->tenant_id);

            // Filter by active status
            if ($request->has('active')) {
                $query->where('active', $request->active);
            }

            // Filter by role
            if ($request->has('role')) {
                $query->where('role', $request->role);
            }

            // Filter current leaders only
            if ($request->has('current') && $request->current) {
                $query->current();
            }

            $leaders = $query->ordered()->get();

            return response()->json([
                'success' => true,
                'data' => $leaders,
                'total' => $leaders->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching church leadership: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching church leadership',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get a specific church leader.
     * 
     * @route GET /api/church-leadership/{id}
     */
    public function show($id): JsonResponse
    {
        try {
            $user = auth()->user();
            
            $leader = ChurchLeadership::where('tenant_id', $user->tenant_id)
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $leader,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching church leader: ' . $e->getMessage(), [
                'leader_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Church leader not found',
                'error' => config('app.debug') ? $e->getMessage() : 'Not found',
            ], 404);
        }
    }

    /**
     * Create a new church leader.
     * 
     * @route POST /api/church-leadership
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || !$user->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            // Check permission
            if (!$user->is_primary_admin && !$user->hasPermissionTo('manage_tenants')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only church administrators can manage leadership.',
                ], 403);
            }

            // Validation
            $validated = $request->validate([
                'full_name' => 'required|string|max:255',
                'role' => 'required|string|max:100',
                'title' => 'nullable|string|max:100',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:20',
                'appointed_date' => 'nullable|date',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after:start_date',
                'biography' => 'nullable|string|max:2000',
                'photo_url' => 'nullable|string|max:255',
                'is_primary' => 'nullable|boolean',
                'display_order' => 'nullable|integer|min:0',
                'active' => 'nullable|boolean',
            ]);

            $validated['tenant_id'] = $user->tenant_id;
            $validated['is_primary'] = $validated['is_primary'] ?? 0;
            $validated['active'] = $validated['active'] ?? 1;
            $validated['display_order'] = $validated['display_order'] ?? 0;

            DB::beginTransaction();
            try {
                $leader = ChurchLeadership::create($validated);

                DB::commit();

                Log::info('Church leader created', [
                    'leader_id' => $leader->id,
                    'tenant_id' => $user->tenant_id,
                    'created_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Church leader added successfully',
                    'data' => $leader,
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating church leader: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error creating church leader',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update a church leader.
     * 
     * @route PUT /api/church-leadership/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || !$user->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            // Check permission
            if (!$user->is_primary_admin && !$user->hasPermissionTo('manage_tenants')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only church administrators can manage leadership.',
                ], 403);
            }

            $leader = ChurchLeadership::where('tenant_id', $user->tenant_id)
                ->findOrFail($id);

            // Validation
            $validated = $request->validate([
                'full_name' => 'sometimes|required|string|max:255',
                'role' => 'sometimes|required|string|max:100',
                'title' => 'nullable|string|max:100',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:20',
                'appointed_date' => 'nullable|date',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after:start_date',
                'biography' => 'nullable|string|max:2000',
                'photo_url' => 'nullable|string|max:255',
                'is_primary' => 'nullable|boolean',
                'display_order' => 'nullable|integer|min:0',
                'active' => 'nullable|boolean',
            ]);

            DB::beginTransaction();
            try {
                $leader->update($validated);

                DB::commit();

                Log::info('Church leader updated', [
                    'leader_id' => $leader->id,
                    'tenant_id' => $user->tenant_id,
                    'updated_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Church leader updated successfully',
                    'data' => $leader,
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating church leader: ' . $e->getMessage(), [
                'leader_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating church leader',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete a church leader.
     * 
     * @route DELETE /api/church-leadership/{id}
     */
    public function destroy($id): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || !$user->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            // Check permission
            if (!$user->is_primary_admin && !$user->hasPermissionTo('manage_tenants')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only church administrators can manage leadership.',
                ], 403);
            }

            $leader = ChurchLeadership::where('tenant_id', $user->tenant_id)
                ->findOrFail($id);

            DB::beginTransaction();
            try {
                $leader->delete();

                DB::commit();

                Log::info('Church leader deleted', [
                    'leader_id' => $id,
                    'tenant_id' => $user->tenant_id,
                    'deleted_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Church leader deleted successfully',
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Error deleting church leader: ' . $e->getMessage(), [
                'leader_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting church leader',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
