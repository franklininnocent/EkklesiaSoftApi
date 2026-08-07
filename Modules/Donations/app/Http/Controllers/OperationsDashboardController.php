<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Services\FinancialActivityTimelineService;
use Modules\Donations\Services\OperationsDashboardService;

class OperationsDashboardController extends Controller
{
    public function __construct(
        private readonly OperationsDashboardService $operationsDashboardService,
        private readonly FinancialActivityTimelineService $timelineService
    ) {
    }

    public function summary(): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            'success' => true,
            'data' => $this->operationsDashboardService->build(
                app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
                $user
            ),
        ]);
    }

    public function timeline(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject_type' => ['required', 'in:family,payment,project,campaign'],
            'subject_id' => ['required', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->timelineService->build(
                app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
                $validated['subject_type'],
                $validated['subject_id'],
                (int) ($validated['limit'] ?? 30)
            ),
        ]);
    }
}
