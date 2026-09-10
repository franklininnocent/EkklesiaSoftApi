<?php

namespace Modules\EcclesiasticalData\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\EcclesiasticalData\Http\Controllers\Concerns\HandlesEcclesiasticalResponses;
use Modules\EcclesiasticalData\Http\Resources\EcclesiasticalLeadershipAssignmentResource;
use Modules\EcclesiasticalData\Services\Leadership\EcclesiasticalLeadershipService;
use Modules\EcclesiasticalData\Support\LeadershipOfficeCode;
use Modules\EcclesiasticalData\Support\LeadershipScope;

class EcclesiasticalLeadershipController extends Controller
{
    use HandlesEcclesiasticalResponses;

    public function __construct(
        private readonly EcclesiasticalLeadershipService $leadershipService,
    ) {}

    public function current(Request $request): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request) {
            $validated = $request->validate([
                'office' => ['required', 'string'],
                'scope' => ['required', 'string', 'in:global,diocese,parish'],
                'scope_id' => ['nullable', 'integer'],
                'tenant_id' => ['nullable', 'integer'],
            ]);

            $scope = match ($validated['scope']) {
                'global' => LeadershipScope::global(),
                'diocese' => LeadershipScope::diocese((int) $validated['scope_id']),
                'parish' => LeadershipScope::parish(
                    (int) $validated['scope_id'],
                    isset($validated['tenant_id']) ? (int) $validated['tenant_id'] : null,
                ),
            };

            LeadershipOfficeCode::from($validated['office']);
            $assignment = $this->leadershipService->getCurrent($validated['office'], $scope);

            return $this->ecclesiasticalSuccess(
                $assignment ? new EcclesiasticalLeadershipAssignmentResource($assignment) : null,
                'Current leadership retrieved successfully',
            );
        }, 'Failed to retrieve current leadership');
    }
}
