<?php

namespace Modules\Sacraments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sacraments\Services\SacramentService;
use Modules\Sacraments\Models\SacramentType;

class SacramentController extends Controller
{
    public function __construct(protected SacramentService $service) {}

    /**
     * Get paginated list of sacraments
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $params = $request->only([
                'tenant_id', 'sacrament_type_id', 'status', 'search',
                'date_from', 'date_to', 'per_page', 'sort_by', 'sort_dir'
            ]);

            $sacraments = $this->service->getAll($params);

            return response()->json([
                'success' => true,
                'data' => $sacraments,
                'message' => 'Sacraments retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacraments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single sacrament by ID
     */
    public function show(int $id): JsonResponse
    {
        try {
            $sacrament = $this->service->getById($id);

            if (!$sacrament) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $sacrament,
                'message' => 'Sacrament retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacrament',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create new sacrament record
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'tenant_id' => 'required|exists:tenants,id',
                'sacrament_type_id' => 'required|exists:sacrament_types,id',
                'recipient_name' => 'required|string|max:255',
                'date_administered' => 'required|date',
                'place_administered' => 'nullable|string|max:255',
                'minister_name' => 'nullable|string|max:255',
                'minister_title' => 'nullable|string|max:50',
                'certificate_number' => 'nullable|string|max:255|unique:sacraments',
                'book_number' => 'nullable|string|max:255',
                'page_number' => 'nullable|string|max:255',
                'recipient_birth_date' => 'nullable|date',
                'recipient_birth_place' => 'nullable|string|max:255',
                'father_name' => 'nullable|string|max:255',
                'mother_name' => 'nullable|string|max:255',
                'godparent1_name' => 'nullable|string|max:255',
                'godparent2_name' => 'nullable|string|max:255',
                'witnesses' => 'nullable|string',
                'notes' => 'nullable|string',
                'status' => 'nullable|in:active,cancelled,conditional',
            ]);

            $sacrament = $this->service->create($validated);

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
            return response()->json([
                'success' => false,
                'message' => 'Failed to create sacrament',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update existing sacrament record
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'recipient_name' => 'sometimes|string|max:255',
                'date_administered' => 'sometimes|date',
                'place_administered' => 'nullable|string|max:255',
                'minister_name' => 'nullable|string|max:255',
                'minister_title' => 'nullable|string|max:50',
                'certificate_number' => 'nullable|string|max:255|unique:sacraments,certificate_number,' . $id,
                'book_number' => 'nullable|string|max:255',
                'page_number' => 'nullable|string|max:255',
                'recipient_birth_date' => 'nullable|date',
                'recipient_birth_place' => 'nullable|string|max:255',
                'father_name' => 'nullable|string|max:255',
                'mother_name' => 'nullable|string|max:255',
                'godparent1_name' => 'nullable|string|max:255',
                'godparent2_name' => 'nullable|string|max:255',
                'witnesses' => 'nullable|string',
                'notes' => 'nullable|string',
                'status' => 'nullable|in:active,cancelled,conditional',
            ]);

            $sacrament = $this->service->update($id, $validated);

            if (!$sacrament) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament not found'
                ], 404);
            }

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
            return response()->json([
                'success' => false,
                'message' => 'Failed to update sacrament',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete sacrament record
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $deleted = $this->service->delete($id);

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Sacrament deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete sacrament',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all sacrament types
     */
    public function getSacramentTypes(): JsonResponse
    {
        try {
            $types = SacramentType::active()->ordered()->get();

            return response()->json([
                'success' => true,
                'data' => $types,
                'message' => 'Sacrament types retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacrament types',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

