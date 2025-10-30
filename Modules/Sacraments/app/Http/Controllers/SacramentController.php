<?php

namespace Modules\Sacraments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sacraments\Services\SacramentService;
use Modules\Sacraments\Models\SacramentType;

/**
 * SacramentController - Tenant Sacrament Records Management
 * 
 * This controller handles CRUD operations for Sacrament Records.
 * Only Tenant users can access these endpoints. Ekklesia users are blocked.
 * 
 * Note: Sacrament Types are managed by Ekklesia users in the
 * EcclesiasticalData module and are read-only here.
 */
class SacramentController extends Controller
{
    public function __construct(protected SacramentService $service) {}

    /**
     * Verify user is a tenant user (not Ekklesia role)
     */
    private function verifyTenantUser(Request $request): ?JsonResponse
    {
        $user = $request->user();
        
        // Check if user has tenant_id (tenant users must have this)
        if (!$user->tenant_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only tenant users can manage sacrament records.',
            ], 403);
        }

        // Block Ekklesia roles from accessing tenant sacrament records
        if ($user->hasEkklesiaRole()) {
            return response()->json([
                'success' => false,
                'message' => 'Ekklesia users cannot access tenant sacrament records. Please use a tenant account.',
            ], 403);
        }

        return null; // User is authorized
    }

    /**
     * Get paginated list of sacraments (tenant-isolated)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Verify tenant user
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $user = $request->user();
            
            // Get params and enforce tenant isolation
            $params = $request->only([
                'sacrament_type_id', 'status', 'search',
                'date_from', 'date_to', 'per_page', 'sort_by', 'sort_dir'
            ]);
            
            // Force tenant_id to current user's tenant
            $params['tenant_id'] = $user->tenant_id;

            $sacraments = $this->service->getAll($params);

            return response()->json([
                'success' => true,
                'data' => $sacraments,
                'message' => 'Sacraments retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching sacraments', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacraments',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    /**
     * Get single sacrament by ID (tenant-isolated)
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            // Verify tenant user
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $user = $request->user();
            $sacrament = $this->service->getById($id);

            if (!$sacrament) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament not found'
                ], 404);
            }

            // Verify sacrament belongs to user's tenant
            if ($sacrament->tenant_id !== $user->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only access sacraments from your own tenant.',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $sacrament,
                'message' => 'Sacrament retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching sacrament', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacrament',
            ], 500);
        }
    }

    /**
     * Create new sacrament record (tenant-isolated)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Verify tenant user
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $user = $request->user();

            $validated = $request->validate([
                'sacrament_type_id' => 'required|exists:sacrament_types,id',
                'recipient_name' => 'required|string|max:255',
                'recipient_dob' => 'nullable|date',
                'date_administered' => 'required|date',
                'place_administered' => 'nullable|string|max:255',
                'minister_name' => 'nullable|string|max:255',
                'certificate_number' => 'nullable|string|max:255|unique:sacraments',
                'parent_names' => 'nullable|string',
                'godparent_names' => 'nullable|string',
                'sponsor_names' => 'nullable|string',
                'notes' => 'nullable|string',
                'status' => 'nullable|in:pending,completed,cancelled',
            ]);

            // Auto-set tenant_id from authenticated user
            $validated['tenant_id'] = $user->tenant_id;
            $validated['created_by'] = $user->id;

            $sacrament = $this->service->create($validated);

            Log::info('Sacrament record created', [
                'sacrament_id' => $sacrament->id,
                'tenant_id' => $user->tenant_id,
                'created_by' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'data' => $sacrament,
                'message' => 'Sacrament created successfully'
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating sacrament', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create sacrament',
            ], 500);
        }
    }

    /**
     * Update existing sacrament record (tenant-isolated)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            // Verify tenant user
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $user = $request->user();

            // Check if sacrament exists and belongs to user's tenant
            $sacrament = $this->service->getById($id);
            
            if (!$sacrament) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament not found'
                ], 404);
            }

            if ($sacrament->tenant_id !== $user->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only update sacraments from your own tenant.',
                ], 403);
            }

            $validated = $request->validate([
                'recipient_name' => 'sometimes|string|max:255',
                'recipient_dob' => 'nullable|date',
                'date_administered' => 'sometimes|date',
                'place_administered' => 'nullable|string|max:255',
                'minister_name' => 'nullable|string|max:255',
                'certificate_number' => 'nullable|string|max:255|unique:sacraments,certificate_number,' . $id,
                'parent_names' => 'nullable|string',
                'godparent_names' => 'nullable|string',
                'sponsor_names' => 'nullable|string',
                'notes' => 'nullable|string',
                'status' => 'nullable|in:pending,completed,cancelled',
            ]);

            $validated['updated_by'] = $user->id;

            $sacrament = $this->service->update($id, $validated);

            Log::info('Sacrament record updated', [
                'sacrament_id' => $id,
                'updated_by' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'data' => $sacrament,
                'message' => 'Sacrament updated successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating sacrament', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update sacrament',
            ], 500);
        }
    }

    /**
     * Delete sacrament record (tenant-isolated)
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            // Verify tenant user
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $user = $request->user();

            // Check if sacrament exists and belongs to user's tenant
            $sacrament = $this->service->getById($id);
            
            if (!$sacrament) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament not found'
                ], 404);
            }

            if ($sacrament->tenant_id !== $user->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only delete sacraments from your own tenant.',
                ], 403);
            }

            $deleted = $this->service->delete($id);

            Log::info('Sacrament record deleted', [
                'sacrament_id' => $id,
                'deleted_by' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sacrament deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting sacrament', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete sacrament',
            ], 500);
        }
    }

    /**
     * Get all sacrament types (read-only for tenants)
     * 
     * Tenant users can VIEW sacrament types to select them,
     * but cannot CREATE/UPDATE/DELETE types.
     */
    public function getSacramentTypes(Request $request): JsonResponse
    {
        try {
            // Verify tenant user
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $types = SacramentType::where('active', true)
                ->orderBy('display_order')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $types,
                'message' => 'Sacrament types retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching sacrament types', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacrament types',
            ], 500);
        }
    }
}


