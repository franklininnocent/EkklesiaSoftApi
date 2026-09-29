<?php

namespace Modules\Tenants\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\EcclesiasticalData\Services\DioceseLeadershipQueryService;
use Modules\EcclesiasticalData\Services\Leadership\EcclesiasticalLeadershipService;
use Modules\EcclesiasticalData\Support\LeadershipOfficeCode;
use Modules\EcclesiasticalData\Support\LeadershipScope;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Support\TenantContext;

class ChurchDiocesanLeadershipController extends Controller
{
    public function __construct(
        private readonly DioceseLeadershipQueryService $leadershipQuery,
        private readonly EcclesiasticalLeadershipService $leadershipService,
        private readonly TenantContext $tenantContext,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if (! $this->canReadDiocesanLeadership($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permission to view diocesan leadership.',
            ], 403);
        }

        $tenantId = $this->tenantContext->requireEffectiveTenantId();
        $profile = ChurchProfile::query()->where('tenant_id', $tenantId)->first();

        if (! $profile?->archdiocese_id) {
            return response()->json([
                'success' => false,
                'message' => 'Church profile is not linked to a diocese.',
            ], 422);
        }

        $dioceseId = (int) $profile->archdiocese_id;
        $leadership = $this->leadershipQuery->getCurrentLeadership($dioceseId);
        $ordinaryDto = $this->leadershipService->getCurrent(
            LeadershipOfficeCode::DiocesanBishop->value,
            LeadershipScope::diocese($dioceseId),
        );

        return response()->json([
            'success' => true,
            'data' => array_merge($leadership, [
                'ordinary_assignment' => $ordinaryDto?->toArray(),
            ]),
            'message' => 'Diocesan leadership retrieved successfully',
        ]);
    }

    private function canReadDiocesanLeadership(mixed $user): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if (method_exists($user, 'isTenantAdmin') && $user->isTenantAdmin()) {
            return true;
        }

        if ($user->is_primary_admin ?? false) {
            return true;
        }

        foreach (['bishops.view', 'bishops.view_own_requests', 'bishops.submit_update_request'] as $permission) {
            if (method_exists($user, 'hasPermission') && $user->hasPermission($permission)) {
                return true;
            }
        }

        return method_exists($user, 'hasPermission') && $user->hasPermission('church.settings.view');
    }
}
