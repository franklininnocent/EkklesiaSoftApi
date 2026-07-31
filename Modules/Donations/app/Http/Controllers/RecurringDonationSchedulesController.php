<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\StoreRecurringDonationScheduleRequest;
use Modules\Donations\Http\Requests\UpdateRecurringDonationScheduleRequest;
use Modules\Donations\Jobs\RunRecurringDonationSchedulesJob;
use Modules\Donations\Models\RecurringDonationSchedule;
use Modules\Donations\Services\DonationAuditService;
use Modules\Donations\Services\RecurringDonationScheduleService;

class RecurringDonationSchedulesController extends Controller
{
    public function __construct(
        private readonly DonationAuditService $auditService,
        private readonly RecurringDonationScheduleService $recurringService
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $schedules = RecurringDonationSchedule::forTenant($tenantId)
            ->orderBy('next_run_on')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $schedules,
        ]);
    }

    public function store(StoreRecurringDonationScheduleRequest $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();
        $payload = $request->validated();
        $payload['tenant_id'] = $tenantId;
        $payload['created_by'] = $userId;
        $payload['updated_by'] = $userId;

        $schedule = RecurringDonationSchedule::create($payload);
        $this->auditService->log($tenantId, 'recurring.created', 'recurring_schedule', $schedule->id, null, $schedule->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Recurring donation schedule created.',
            'data' => $schedule,
        ], 201);
    }

    public function update(UpdateRecurringDonationScheduleRequest $request, string $id): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();
        $schedule = RecurringDonationSchedule::forTenant($tenantId)->findOrFail($id);
        $oldValues = $schedule->toArray();
        $payload = $request->validated();
        $payload['updated_by'] = $userId;
        $schedule->update($payload);

        $this->auditService->log($tenantId, 'recurring.updated', 'recurring_schedule', $schedule->id, $oldValues, $schedule->fresh()->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Recurring schedule updated.',
            'data' => $schedule,
        ]);
    }

    public function pause(string $id): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();
        $schedule = RecurringDonationSchedule::forTenant($tenantId)->findOrFail($id);
        $schedule = $this->recurringService->updateStatus($schedule, $userId, 'paused');

        return response()->json([
            'success' => true,
            'message' => 'Recurring schedule paused.',
            'data' => $schedule,
        ]);
    }

    public function cancel(string $id): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();
        $schedule = RecurringDonationSchedule::forTenant($tenantId)->findOrFail($id);
        $schedule = $this->recurringService->updateStatus($schedule, $userId, 'cancelled');

        return response()->json([
            'success' => true,
            'message' => 'Recurring schedule cancelled.',
            'data' => $schedule,
        ]);
    }

    public function runDue(): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $result = $this->recurringService->runDueSchedules($tenantId);

        return response()->json([
            'success' => true,
            'message' => 'Due recurring schedules executed.',
            'data' => $result,
        ]);
    }

    public function queueRunDue(): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        RunRecurringDonationSchedulesJob::dispatch($tenantId);

        return response()->json([
            'success' => true,
            'message' => 'Recurring schedule execution queued.',
        ], 202);
    }
}
