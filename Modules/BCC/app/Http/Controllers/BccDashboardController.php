<?php

namespace Modules\BCC\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BCC\Exceptions\BccDomainException;
use Modules\BCC\Http\Controllers\Concerns\RespondsToBccDomain;
use Modules\BCC\Models\BCC;
use Modules\BCC\Services\BccDashboardService;
use Modules\BCC\Services\BccOverviewService;

class BccDashboardController extends Controller
{
    use AuthorizesRequests;
    use RespondsToBccDomain;

    public function __construct(
        private readonly BccDashboardService $dashboardService,
        private readonly BccOverviewService $overviewService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BCC::class);

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'period' => ['sometimes', 'nullable', 'string', 'max:8'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'coordinator' => ['sometimes', 'nullable', 'uuid'],
            'attention' => ['sometimes', 'nullable', 'string', 'max:32'],
            'trend' => ['sometimes', 'nullable', 'string', 'max:32'],
            'overview_limit' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:50'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->dashboardService->summary($this->tenantId(), $filters),
        ]);
    }

    public function overview(string $id): JsonResponse
    {
        $this->authorize('viewAny', BCC::class);

        try {
            return response()->json([
                'success' => true,
                'data' => $this->overviewService->summary($this->tenantId(), $id),
            ]);
        } catch (BccDomainException $e) {
            return $this->domainError($e);
        }
    }
}
