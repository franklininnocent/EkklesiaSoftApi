<?php

namespace Modules\EcclesiasticalData\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\EcclesiasticalData\Http\Controllers\Concerns\HandlesEcclesiasticalResponses;
use Modules\EcclesiasticalData\Http\Requests\ReplaceOrdinaryRequest;
use Modules\EcclesiasticalData\Http\Resources\BishopAppointmentResource;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Services\BishopService;
use Modules\EcclesiasticalData\Services\DioceseLeadershipQueryService;
use Modules\EcclesiasticalData\Services\SuccessionService;
use Modules\EcclesiasticalData\Support\AppointmentEndReason;
use Modules\EcclesiasticalData\Support\CanonicalRole;

class DioceseLeadershipController extends Controller
{
    use HandlesEcclesiasticalResponses;

    public function __construct(
        private readonly DioceseLeadershipQueryService $leadershipQuery,
        private readonly SuccessionService $successionService,
        private readonly BishopService $bishopService,
    ) {}

    public function current(string $dioceseId): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($dioceseId) {
            $diocese = DioceseManagement::query()->findOrFail($dioceseId);
            $this->authorize('view', $diocese);

            return $this->ecclesiasticalSuccess(
                $this->leadershipQuery->getCurrentLeadership((int) $dioceseId),
                'Diocese leadership retrieved successfully'
            );
        }, 'Failed to retrieve diocese leadership');
    }

    public function history(Request $request, string $dioceseId): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $dioceseId) {
            $diocese = DioceseManagement::query()->findOrFail($dioceseId);
            $this->authorize('view', $diocese);

            $role = $request->query('canonical_role')
                ? CanonicalRole::from($request->query('canonical_role'))
                : null;

            $history = $this->leadershipQuery->getAppointmentHistory(
                (int) $dioceseId,
                $role,
                $this->boundedPerPage($request),
                (int) $request->input('page', 1),
            );

            return $this->ecclesiasticalSuccess([
                'data' => BishopAppointmentResource::collection($history->items()),
                'current_page' => $history->currentPage(),
                'last_page' => $history->lastPage(),
                'per_page' => $history->perPage(),
                'total' => $history->total(),
            ], 'Leadership history retrieved successfully');
        }, 'Failed to retrieve leadership history');
    }

    public function ordinaryOnDate(Request $request, string $dioceseId): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $dioceseId) {
            $diocese = DioceseManagement::query()->findOrFail($dioceseId);
            $this->authorize('view', $diocese);

            $date = $request->validate(['date' => ['required', 'date']])['date'];
            $appointment = $this->leadershipQuery->getOrdinaryOnDate((int) $dioceseId, $date);

            return $this->ecclesiasticalSuccess([
                'date' => $date,
                'appointment' => $appointment
                    ? new BishopAppointmentResource($appointment)
                    : null,
            ], 'Ordinary on date retrieved successfully');
        }, 'Failed to retrieve ordinary on date', 422);
    }

    public function replaceOrdinary(ReplaceOrdinaryRequest $request, string $dioceseId): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $dioceseId) {
            $diocese = DioceseManagement::query()->findOrFail($dioceseId);
            $this->authorize('view', $diocese);

            $validated = $request->validated();
            $bishop = isset($validated['bishop_id'])
                ? BishopManagement::query()->findOrFail($validated['bishop_id'])
                : $this->bishopService->createPerson(
                    array_merge($validated['person'], ['archdiocese_id' => $dioceseId]),
                    (int) $request->user()->id,
                    true,
                );

            $endReason = isset($validated['end_reason'])
                ? AppointmentEndReason::from($validated['end_reason'])
                : null;

            $result = $this->successionService->replaceCurrentOrdinary(
                (int) $dioceseId,
                $bishop,
                $validated['appointment'],
                (int) $request->user()->id,
                $endReason,
            );

            return $this->ecclesiasticalSuccess([
                'ended' => $result['ended'] ? new BishopAppointmentResource($result['ended']) : null,
                'created' => new BishopAppointmentResource($result['created']),
                'bishop_id' => $result['bishop']->id,
            ], 'Ordinary succession completed successfully', 201);
        }, 'Failed to replace ordinary', 422);
    }
}
