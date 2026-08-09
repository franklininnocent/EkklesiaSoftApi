<?php

namespace Modules\MinistriesAssociations\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\MinistriesAssociations\Services\Admin\AdminMinistriesTenantsService;
use Modules\MinistriesAssociations\Support\AdminMinistriesDefinitions;
use Modules\MinistriesAssociations\Support\AdminMinistriesWindow;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminMinistriesTenantsController extends Controller
{
    public function __construct(
        private readonly AdminMinistriesTenantsService $tenants,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);
        $payload = $this->tenants->list($filters);

        return response()->json([
            'success' => true,
            'data' => $payload['data'],
            'meta' => $payload['meta'],
            'window' => $payload['window'],
        ]);
    }

    public function show(Request $request, int $tenantId): JsonResponse
    {
        $validated = $request->validate([
            'window_days' => ['sometimes', 'integer', 'in:'.implode(',', AdminMinistriesWindow::ALLOWED_DAYS)],
        ]);

        $windowDays = (int) ($validated['window_days'] ?? AdminMinistriesDefinitions::DEFAULT_ACTIVITY_WINDOW_DAYS);

        try {
            $data = $this->tenants->detail($tenantId, $windowDays);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validateFilters($request);

        return $this->tenants->exportCsv($filters);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'window_days' => ['sometimes', 'integer', 'in:'.implode(',', AdminMinistriesWindow::ALLOWED_DAYS)],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'module_status' => ['sometimes', 'nullable', 'in:enabled,disabled'],
            'adoption_status' => ['sometimes', 'nullable', 'in:disabled,not_started,activated,active,highly_engaged,inactive,declining'],
            'usage_status' => ['sometimes', 'nullable', 'in:up,flat,down'],
            'health' => ['sometimes', 'nullable', 'in:ok,attention,critical,without_members,without_leadership,stale'],
            'health_flag' => ['sometimes', 'nullable', 'in:ok,attention,critical'],
            'last_activity_from' => ['sometimes', 'nullable', 'date'],
            'last_activity_to' => ['sometimes', 'nullable', 'date'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:60'],
            'direction' => ['sometimes', 'nullable', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
    }
}
