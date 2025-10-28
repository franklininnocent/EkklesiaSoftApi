<?php

namespace Modules\EcclesiasticalData\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\EcclesiasticalData\Services\BishopService;
use Modules\EcclesiasticalData\Http\Requests\StoreBishopRequest;
use Modules\EcclesiasticalData\Http\Requests\UpdateBishopRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BishopController extends Controller
{
    protected BishopService $service;

    public function __construct(BishopService $service)
    {
        $this->service = $service;
    }

    /**
     * Display a paginated listing of bishops
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $params = $request->only([
                'search',
                'diocese_id',
                'title_id',
                'is_active',
                'sort_by',
                'sort_dir',
                'per_page'
            ]);

            $bishops = $this->service->getPaginated($params);

            return response()->json([
                'success' => true,
                'data' => $bishops,
                'message' => 'Bishops retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve bishops',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created bishop
     */
    public function store(StoreBishopRequest $request): JsonResponse
    {
        try {
            $bishop = $this->service->create($request->validated());

            return response()->json([
                'success' => true,
                'data' => $bishop,
                'message' => 'Bishop created successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create bishop',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified bishop
     */
    public function show(string $id): JsonResponse
    {
        try {
            $bishop = $this->service->getById($id);

            return response()->json([
                'success' => true,
                'data' => $bishop,
                'message' => 'Bishop retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Bishop not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified bishop
     */
    public function update(UpdateBishopRequest $request, string $id): JsonResponse
    {
        try {
            $bishop = $this->service->update($id, $request->validated());

            return response()->json([
                'success' => true,
                'data' => $bishop,
                'message' => 'Bishop updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update bishop',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified bishop
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $this->service->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Bishop deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete bishop',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get bishops by diocese
     */
    public function byDiocese(Request $request, string $dioceseId): JsonResponse
    {
        try {
            $currentOnly = $request->boolean('current_only', true);
            $bishops = $this->service->getByDiocese($dioceseId, $currentOnly);

            return response()->json([
                'success' => true,
                'data' => $bishops,
                'message' => 'Bishops retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve bishops',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get bishops by title
     */
    public function byTitle(Request $request, string $titleId): JsonResponse
    {
        try {
            $bishops = $this->service->getByTitle($titleId);

            return response()->json([
                'success' => true,
                'data' => $bishops,
                'message' => 'Bishops retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve bishops',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get bishop statistics
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
     * Get audit history for a bishop
     */
    public function auditHistory(string $id): JsonResponse
    {
        try {
            $bishop = $this->service->getById($id);
            $history = $bishop->auditHistory();

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

