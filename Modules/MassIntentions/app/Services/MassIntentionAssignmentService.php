<?php

namespace Modules\MassIntentions\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassIntentionAssignment;
use Modules\MassIntentions\Models\MassIntentionObligation;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Support\MassObligationStatus;

class MassIntentionAssignmentService
{
    public function __construct(
        private readonly MassIntentionAuditService $audits,
    ) {
    }

    public function tenantHasActiveAssignment(int $tenantId, string $requestId): bool
    {
        return $this->activeAssignmentQuery($tenantId, $requestId)->exists();
    }

    /**
     * @return array{obligation_id: string, assignment_id: string, celebration_id: string}|null
     */
    public function activeAssignmentForRequest(int $tenantId, string $requestId): ?array
    {
        $row = $this->activeAssignmentQuery($tenantId, $requestId)
            ->select([
                'mass_intention_assignments.id as assignment_id',
                'mass_intention_assignments.celebration_id',
                'mass_intention_obligations.id as obligation_id',
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'obligation_id' => (string) $row->obligation_id,
            'assignment_id' => (string) $row->assignment_id,
            'celebration_id' => (string) $row->celebration_id,
        ];
    }

    public function lockCelebrationForAssignment(int $tenantId, string $celebrationId): MassCelebration
    {
        $celebration = MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $celebrationId)
            ->lockForUpdate()
            ->first();

        if ($celebration === null) {
            throw ValidationException::withMessages([
                'celebration_id' => 'This Mass was not found.',
            ]);
        }

        if (! MassCelebrationEligibility::isEligibleForIntentionAssignment($celebration)) {
            throw ValidationException::withMessages([
                'celebration_id' => 'This Mass cannot accept intentions.',
            ]);
        }

        return $celebration;
    }

    public function createObligationAndAssignment(
        int $tenantId,
        User $actor,
        MassIntentionRequest $request,
        MassCelebration $celebration
    ): void {
        $obligation = MassIntentionObligation::query()->create([
            'tenant_id' => $tenantId,
            'request_id' => $request->id,
            'sequence' => 1,
            'status' => MassObligationStatus::SCHEDULED,
        ]);

        MassIntentionAssignment::query()->create([
            'tenant_id' => $tenantId,
            'obligation_id' => $obligation->id,
            'celebration_id' => $celebration->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $actor->id,
        ]);
    }

    public function assignLegacyOpenIntention(
        int $tenantId,
        User $actor,
        MassIntentionRequest $request,
        string $celebrationId
    ): void {
        if ($this->tenantHasActiveAssignment($tenantId, $request->id)) {
            return;
        }

        DB::transaction(function () use ($tenantId, $actor, $request, $celebrationId): void {
            $celebration = $this->lockCelebrationForAssignment($tenantId, $celebrationId);

            $obligation = MassIntentionObligation::query()
                ->where('tenant_id', $tenantId)
                ->where('request_id', $request->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->first();

            if ($obligation === null) {
                $obligation = MassIntentionObligation::query()->create([
                    'tenant_id' => $tenantId,
                    'request_id' => $request->id,
                    'sequence' => 1,
                    'status' => MassObligationStatus::SCHEDULED,
                ]);
            } elseif ($obligation->status === MassObligationStatus::SAID) {
                throw ValidationException::withMessages([
                    'celebration_id' => 'This intention is already marked said.',
                ]);
            } else {
                $obligation->status = MassObligationStatus::SCHEDULED;
                $obligation->save();
            }

            MassIntentionAssignment::query()->create([
                'tenant_id' => $tenantId,
                'obligation_id' => $obligation->id,
                'celebration_id' => $celebration->id,
                'assigned_at' => now(),
                'assigned_by_user_id' => $actor->id,
            ]);

            $request->requested_date = $celebration->celebrated_on;
            $request->save();

            $this->audits->record($tenantId, 'intention.assigned', $actor, $request->id, $celebration->id, [
                'mass' => MassCelebrationEligibility::massSnapshot($celebration),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function moveRequest(
        int $tenantId,
        User $actor,
        string $requestId,
        string $targetCelebrationId,
        ?string $reason = null
    ): array {
        return $this->bulkMoveRequests($tenantId, $actor, [$requestId], $targetCelebrationId, $reason);
    }

    /**
     * @param  list<string>  $requestIds
     * @return array<string, mixed>
     */
    public function bulkMoveRequests(
        int $tenantId,
        User $actor,
        array $requestIds,
        string $targetCelebrationId,
        ?string $reason = null
    ): array {
        $requestIds = array_values(array_unique(array_filter($requestIds)));
        if ($requestIds === []) {
            throw ValidationException::withMessages([
                'intention_ids' => 'Select at least one intention.',
            ]);
        }

        sort($requestIds);

        return DB::transaction(function () use ($tenantId, $actor, $requestIds, $targetCelebrationId, $reason): array {
            $target = $this->lockCelebrationForAssignment($tenantId, $targetCelebrationId);
            $targetSnapshot = MassCelebrationEligibility::massSnapshot($target);

            $sourceMassIds = [];
            $auditIds = [];

            foreach ($requestIds as $requestId) {
                $request = MassIntentionRequest::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $requestId)
                    ->lockForUpdate()
                    ->first();

                if ($request === null) {
                    throw ValidationException::withMessages([
                        'intention_ids' => 'One or more intentions were not found.',
                    ]);
                }

                if (! MassIntentionStatus::isOpen($request->status)) {
                    throw ValidationException::withMessages([
                        'intention_ids' => 'Closed intentions cannot be moved.',
                    ]);
                }

                $active = $this->activeAssignmentQuery($tenantId, $requestId)
                    ->lockForUpdate()
                    ->select([
                        'mass_intention_assignments.id as assignment_id',
                        'mass_intention_assignments.celebration_id',
                        'mass_intention_obligations.id as obligation_id',
                        'mass_intention_obligations.status as obligation_status',
                    ])
                    ->first();

                if ($active === null) {
                    throw ValidationException::withMessages([
                        'intention_ids' => 'One or more intentions are not on a Mass yet.',
                    ]);
                }

                if ($active->obligation_status === MassObligationStatus::SAID) {
                    throw ValidationException::withMessages([
                        'intention_ids' => 'Said intentions cannot be moved.',
                    ]);
                }

                if ((string) $active->celebration_id === $targetCelebrationId) {
                    throw ValidationException::withMessages([
                        'target_celebration_id' => 'One or more intentions are already on that Mass.',
                    ]);
                }

                $sourceMassIds[(string) $active->celebration_id] = true;

                $fromCelebration = MassCelebration::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $active->celebration_id)
                    ->lockForUpdate()
                    ->first();

                if ($fromCelebration === null) {
                    throw ValidationException::withMessages([
                        'intention_ids' => 'The current Mass for one intention was not found.',
                    ]);
                }

                MassIntentionAssignment::query()
                    ->where('id', $active->assignment_id)
                    ->update(['unassigned_at' => now()]);

                MassIntentionAssignment::query()->create([
                    'tenant_id' => $tenantId,
                    'obligation_id' => $active->obligation_id,
                    'celebration_id' => $targetCelebrationId,
                    'assigned_at' => now(),
                    'assigned_by_user_id' => $actor->id,
                    'date_variance_reason' => $reason,
                ]);

                $request->requested_date = $target->celebrated_on;
                $request->save();

                $auditIds[] = $this->audits->record($tenantId, 'intention.moved', $actor, $requestId, $targetCelebrationId, [
                    'from' => MassCelebrationEligibility::massSnapshot($fromCelebration),
                    'to' => $targetSnapshot,
                    'reason' => $reason,
                ]);
            }

            return [
                'moved_count' => count($requestIds),
                'source_mass_count' => count($sourceMassIds),
                'target_mass' => $targetSnapshot,
                'audit_ids' => $auditIds,
            ];
        });
    }

    /**
     * @param  list<array{obligation_id: string, celebration_id: string}>  $moves
     */
    public function reassignUnsaidForCancel(
        int $tenantId,
        User $actor,
        string $sourceCelebrationId,
        array $moves
    ): void {
        foreach ($moves as $move) {
            $obligationId = $move['obligation_id'];
            $targetId = $move['celebration_id'];

            if ($targetId === $sourceCelebrationId) {
                throw ValidationException::withMessages([
                    'reassignments' => 'Choose a different Mass than the one being cancelled.',
                ]);
            }

            $obligation = MassIntentionObligation::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $obligationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($obligation->status === MassObligationStatus::SAID) {
                continue;
            }

            $requestId = (string) $obligation->request_id;
            $this->bulkMoveRequests($tenantId, $actor, [$requestId], $targetId, 'Mass cancelled');
        }
    }

    public function syncRequestedDateForUnsaidOnCelebration(int $tenantId, MassCelebration $celebration): void
    {
        $date = $celebration->celebrated_on?->format('Y-m-d');
        if ($date === null) {
            return;
        }

        $requestIds = DB::table('mass_intention_assignments as a')
            ->join('mass_intention_obligations as o', 'o.id', '=', 'a.obligation_id')
            ->join('mass_intention_requests as r', 'r.id', '=', 'o.request_id')
            ->where('a.tenant_id', $tenantId)
            ->where('a.celebration_id', $celebration->id)
            ->whereNull('a.unassigned_at')
            ->where('o.status', '!=', MassObligationStatus::SAID)
            ->where('r.status', MassIntentionStatus::OPEN)
            ->pluck('r.id');

        if ($requestIds->isEmpty()) {
            return;
        }

        MassIntentionRequest::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $requestIds)
            ->update(['requested_date' => $date]);
    }

    public function celebrationHasSaidFulfilment(int $tenantId, string $celebrationId): bool
    {
        return DB::table('mass_intention_fulfilments')
            ->where('tenant_id', $tenantId)
            ->where('celebration_id', $celebrationId)
            ->whereNull('undone_at')
            ->exists();
    }

    /**
     * @param  list<string>  $requestIds
     * @return array<string, string> request_id => celebration_id
     */
    public function activeCelebrationIdsForRequests(int $tenantId, array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        $rows = DB::table('mass_intention_assignments as a')
            ->join('mass_intention_obligations as o', 'o.id', '=', 'a.obligation_id')
            ->where('a.tenant_id', $tenantId)
            ->whereNull('a.unassigned_at')
            ->whereIn('o.request_id', $requestIds)
            ->select(['o.request_id', 'a.celebration_id'])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->request_id] = (string) $row->celebration_id;
        }

        return $map;
    }

    private function activeAssignmentQuery(int $tenantId, string $requestId)
    {
        return DB::table('mass_intention_assignments')
            ->join('mass_intention_obligations', 'mass_intention_obligations.id', '=', 'mass_intention_assignments.obligation_id')
            ->where('mass_intention_assignments.tenant_id', $tenantId)
            ->where('mass_intention_obligations.request_id', $requestId)
            ->whereNull('mass_intention_assignments.unassigned_at');
    }
}
