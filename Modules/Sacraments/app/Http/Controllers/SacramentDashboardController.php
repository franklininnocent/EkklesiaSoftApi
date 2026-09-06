<?php

namespace Modules\Sacraments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sacraments\Services\SacramentDashboardService;
use Modules\Sacraments\Support\SacramentPrivacyAccess;
use Modules\Tenants\Support\TenantContext;

class SacramentDashboardController extends Controller
{
    public function __construct(
        protected SacramentDashboardService $dashboardService,
        protected SacramentPrivacyAccess $privacyAccess
    ) {}

    public function summary(Request $request): JsonResponse
    {
        try {
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $validated = $request->validate([
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'bcc_id' => 'nullable|uuid',
                'include_gaps' => 'nullable|boolean',
                'include_marriage_gaps' => 'nullable|boolean',
            ]);

            if (! empty($validated['date_from']) && ! empty($validated['date_to'])
                && $validated['date_from'] > $validated['date_to']) {
                return response()->json([
                    'success' => false,
                    'message' => 'date_from must be on or before date_to.',
                ], 422);
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $restrictedCodes = $this->privacyAccess->canViewRestricted($request->user())
                ? []
                : $this->privacyAccess->restrictedTypeCodes();

            $summary = $this->dashboardService->getSummary(
                $tenantId,
                $validated,
                $restrictedCodes
            );

            return response()->json([
                'success' => true,
                'data' => $summary,
                'message' => 'Sacrament dashboard summary retrieved successfully',
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error fetching sacrament dashboard summary', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacrament dashboard summary',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred',
            ], 500);
        }
    }

    private function verifyTenantUser(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (app(TenantContext::class)->effectiveTenantId() === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only tenant users can view sacrament analytics.',
            ], 403);
        }

        if ($user->hasEkklesiaRole()) {
            return response()->json([
                'success' => false,
                'message' => 'Ekklesia users cannot access tenant sacrament analytics. Please use a tenant account.',
            ], 403);
        }

        return null;
    }
}
