<?php

namespace Modules\EcclesiasticalData\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\EcclesiasticalData\Http\Controllers\Concerns\HandlesEcclesiasticalResponses;
use Modules\EcclesiasticalData\Http\Requests\ActivateSuccessionRequest;
use Modules\EcclesiasticalData\Http\Requests\EndAppointmentRequest;
use Modules\EcclesiasticalData\Http\Requests\StoreAppointmentRequest;
use Modules\EcclesiasticalData\Http\Requests\UpdateAppointmentRequest;
use Modules\EcclesiasticalData\Http\Resources\BishopAppointmentResource;
use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Services\EpiscopalAppointmentService;
use Modules\EcclesiasticalData\Services\SuccessionService;
use Modules\EcclesiasticalData\Support\AppointmentEndReason;
use Modules\EcclesiasticalData\Support\CanonicalRole;

class BishopAppointmentController extends Controller
{
    use HandlesEcclesiasticalResponses;

    public function __construct(
        private readonly EpiscopalAppointmentService $appointmentService,
        private readonly SuccessionService $successionService,
    ) {}

    public function indexForBishop(Request $request, string $bishopId): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $bishopId) {
            $bishop = BishopManagement::query()->findOrFail($bishopId);
            $this->authorize('view', $bishop);

            $appointments = BishopAppointment::query()
                ->where('bishop_id', $bishopId)
                ->with(['diocese', 'ecclesiasticalTitle', 'bishop'])
                ->orderByDesc('effective_date')
                ->paginate(
                    $this->boundedPerPage($request),
                    ['*'],
                    'page',
                    (int) $request->input('page', 1),
                );

            return $this->ecclesiasticalSuccess([
                'data' => array_values(array_map(
                    static fn (BishopAppointment $appointment) => (new BishopAppointmentResource($appointment))->resolve($request),
                    $appointments->items()
                )),
                'current_page' => $appointments->currentPage(),
                'last_page' => $appointments->lastPage(),
                'per_page' => $appointments->perPage(),
                'total' => $appointments->total(),
            ], 'Appointments retrieved successfully');
        }, 'Failed to retrieve appointments');
    }

    public function show(string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($id) {
            $appointment = BishopAppointment::query()
                ->with(['bishop', 'diocese', 'ecclesiasticalTitle'])
                ->findOrFail($id);

            $this->authorize('view', $appointment->bishop);

            return $this->ecclesiasticalSuccess(
                new BishopAppointmentResource($appointment),
                'Appointment retrieved successfully'
            );
        }, 'Appointment not found');
    }

    public function store(StoreAppointmentRequest $request, string $bishopId): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $bishopId) {
            $bishop = BishopManagement::query()->findOrFail($bishopId);
            $appointment = $this->appointmentService->create(
                array_merge($request->validated(), ['bishop_id' => $bishop->id]),
                (int) $request->user()->id,
            );

            return $this->ecclesiasticalSuccess(
                new BishopAppointmentResource($appointment->load(['bishop', 'diocese', 'ecclesiasticalTitle'])),
                'Appointment created successfully',
                201
            );
        }, 'Failed to create appointment', 422);
    }

    public function update(UpdateAppointmentRequest $request, string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $id) {
            $appointment = BishopAppointment::query()->findOrFail($id);
            $this->appointmentService->validateTemporalDates(array_merge(
                $appointment->only(['effective_date', 'appointed_date', 'installed_date', 'ended_date', 'announced_date']),
                $request->validated()
            ));

            $appointment->fill(array_merge($request->validated(), [
                'updated_by' => $request->user()->id,
            ]));
            $appointment->save();

            return $this->ecclesiasticalSuccess(
                new BishopAppointmentResource($appointment->fresh(['bishop', 'diocese', 'ecclesiasticalTitle'])),
                'Appointment updated successfully'
            );
        }, 'Failed to update appointment', 422);
    }

    public function end(EndAppointmentRequest $request, string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $id) {
            $appointment = BishopAppointment::query()->findOrFail($id);
            $validated = $request->validated();

            $ended = $this->appointmentService->end(
                $appointment,
                $validated['ended_date'],
                AppointmentEndReason::from($validated['end_reason']),
                (int) $request->user()->id,
            );

            return $this->ecclesiasticalSuccess(
                new BishopAppointmentResource($ended),
                'Appointment ended successfully'
            );
        }, 'Failed to end appointment', 422);
    }

    public function activate(Request $request, string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $id) {
            $appointment = BishopAppointment::query()->findOrFail($id);
            $bishop = BishopManagement::query()->findOrFail($appointment->bishop_id);
            $this->authorize('manageAppointments', $bishop);

            $activated = $this->appointmentService->activate($appointment, (int) $request->user()->id);

            return $this->ecclesiasticalSuccess(
                new BishopAppointmentResource($activated),
                'Appointment activated successfully'
            );
        }, 'Failed to activate appointment', 422);
    }

    public function activateSuccession(ActivateSuccessionRequest $request, string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $id) {
            $appointment = BishopAppointment::query()->findOrFail($id);
            $endReason = $request->validated('end_reason')
                ? AppointmentEndReason::from($request->validated('end_reason'))
                : null;

            $result = $this->successionService->activateOrdinarySuccession(
                $appointment,
                (int) $request->user()->id,
                $endReason,
            );

            return $this->ecclesiasticalSuccess([
                'ended' => $result['ended'] ? new BishopAppointmentResource($result['ended']) : null,
                'activated' => new BishopAppointmentResource($result['activated']),
            ], 'Succession activated successfully');
        }, 'Failed to activate succession', 422);
    }
}
