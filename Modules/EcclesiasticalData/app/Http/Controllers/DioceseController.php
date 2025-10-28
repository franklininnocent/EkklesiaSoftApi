<?php

namespace Modules\EcclesiasticalData\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\EcclesiasticalData\Services\DioceseService;
use Modules\EcclesiasticalData\Http\Requests\StoreDioceseRequest;
use Modules\EcclesiasticalData\Http\Requests\UpdateDioceseRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DioceseController extends Controller
{
    protected DioceseService $service;

    public function __construct(DioceseService $service)
    {
        $this->service = $service;
    }

    /**
     * Display a paginated listing of dioceses
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $params = $request->only([
                'search',
                'country_id',
                'denomination_id',
                'is_active',
                'sort_by',
                'sort_dir',
                'per_page'
            ]);

            $dioceses = $this->service->getPaginated($params);

            return response()->json([
                'success' => true,
                'data' => $dioceses,
                'message' => 'Dioceses retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dioceses',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created diocese
     */
    public function store(StoreDioceseRequest $request): JsonResponse
    {
        try {
            $diocese = $this->service->create($request->validated());

            return response()->json([
                'success' => true,
                'data' => $diocese,
                'message' => 'Diocese created successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create diocese',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified diocese
     */
    public function show(string $id): JsonResponse
    {
        try {
            $diocese = $this->service->getById($id);

            return response()->json([
                'success' => true,
                'data' => $diocese,
                'message' => 'Diocese retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Diocese not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified diocese
     */
    public function update(UpdateDioceseRequest $request, string $id): JsonResponse
    {
        try {
            $diocese = $this->service->update($id, $request->validated());

            return response()->json([
                'success' => true,
                'data' => $diocese,
                'message' => 'Diocese updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update diocese',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified diocese
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $this->service->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Diocese deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete diocese',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get dioceses by country
     */
    public function byCountry(Request $request, int $countryId): JsonResponse
    {
        try {
            $dioceses = $this->service->getByCountry($countryId);

            return response()->json([
                'success' => true,
                'data' => $dioceses,
                'message' => 'Dioceses retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dioceses',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get archdioceses only
     */
    public function archdioceses(): JsonResponse
    {
        try {
            $archdioceses = $this->service->getArchdioceses();

            return response()->json([
                'success' => true,
                'data' => $archdioceses,
                'message' => 'Archdioceses retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve archdioceses',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get diocese statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = $this->service->getStatistics();

            return response()->json([
                'success' => true,
                'data' => $stats,
                'message' => 'Statistics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get audit history for a diocese
     */
    public function auditHistory(string $id): JsonResponse
    {
        try {
            $diocese = $this->service->getById($id);
            $history = $diocese->auditHistory();

            return response()->json([
                'success' => true,
                'data' => $history,
                'message' => 'Audit history retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve audit history',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

