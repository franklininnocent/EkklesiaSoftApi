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

class MassIntentionSchedulingService
{
    public function __construct(
        private readonly MassIntentionCanonService $canon,
    ) {
    }

    public function assignNextObligation(
        int $tenantId,
        User $actor,
        MassIntentionRequest $request,
        string $celebrationId,
        ?string $dateVarianceReason = null
    ): MassIntentionObligation {
        if ($request->status !== MassIntentionStatus::ACCEPTED) {
            throw ValidationException::withMessages([
                'status' => 'Only accepted intentions can be scheduled.',
            ]);
        }

        $celebration = $this->resolveScheduledCelebration($tenantId, $celebrationId);
        $this->canon->assertCelebrationWithinOneYear($request, $celebration);
        $storedReason = $this->resolveDateVarianceReason($request, $celebration, $dateVarianceReason);

        $obligation = MassIntentionObligation::query()
            ->where('tenant_id', $tenantId)
            ->where('request_id', $request->id)
            ->where('status', MassObligationStatus::PENDING)
            ->orderBy('sequence')
            ->first();

        if (! $obligation) {
            throw ValidationException::withMessages([
                'celebration_id' => 'Every Mass for this intention is already scheduled.',
            ]);
        }

        return DB::transaction(function () use ($tenantId, $actor, $obligation, $celebrationId, $storedReason): MassIntentionObligation {
            MassIntentionAssignment::query()->create([
                'tenant_id' => $tenantId,
                'obligation_id' => $obligation->id,
                'celebration_id' => $celebrationId,
                'assigned_at' => now(),
                'assigned_by_user_id' => $actor->id,
                'date_variance_reason' => $storedReason,
            ]);

            $obligation->status = MassObligationStatus::SCHEDULED;
            $obligation->save();

            return $obligation->fresh();
        });
    }

    /**
     * @param  list<string>  $obligationIds
     */
    public function assignObligationsToCelebration(
        int $tenantId,
        User $actor,
        string $celebrationId,
        array $obligationIds,
        ?string $dateVarianceReason = null
    ): int {
        $celebration = $this->resolveScheduledCelebration($tenantId, $celebrationId);

        foreach (array_unique($obligationIds) as $obligationId) {
            $obligation = MassIntentionObligation::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $obligationId)
                ->first();
            if ($obligation) {
                $request = MassIntentionRequest::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $obligation->request_id)
                    ->first();
                if ($request) {
                    $this->canon->assertCelebrationWithinOneYear($request, $celebration);
                }
            }
        }

        $uniqueIds = array_values(array_unique($obligationIds));
        if ($uniqueIds === []) {
            throw ValidationException::withMessages([
                'obligation_ids' => 'Pick at least one intention.',
            ]);
        }

        $assigned = 0;

        $needsReason = false;
        foreach ($uniqueIds as $obligationId) {
            $obligation = MassIntentionObligation::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $obligationId)
                ->first();
            if ($obligation) {
                $request = MassIntentionRequest::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $obligation->request_id)
                    ->first();
                if ($request && $this->dateVarianceRequired($request, $celebration)) {
                    $needsReason = true;
                    break;
                }
            }
        }

        $batchReason = null;
        if ($needsReason) {
            $batchReason = trim((string) $dateVarianceReason);
            if ($batchReason === '') {
                throw ValidationException::withMessages([
                    'date_variance_reason' => 'The Mass is on a different day. Say why this date is acceptable.',
                ]);
            }
        }

        DB::transaction(function () use ($tenantId, $actor, $celebration, $celebrationId, $uniqueIds, $batchReason, &$assigned): void {
            foreach ($uniqueIds as $obligationId) {
                $obligation = MassIntentionObligation::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $obligationId)
                    ->where('status', MassObligationStatus::PENDING)
                    ->first();

                if (! $obligation) {
                    throw ValidationException::withMessages([
                        'obligation_ids' => 'One of the intentions is no longer available to schedule.',
                    ]);
                }

                $request = MassIntentionRequest::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $obligation->request_id)
                    ->where('status', MassIntentionStatus::ACCEPTED)
                    ->first();

                if (! $request) {
                    throw ValidationException::withMessages([
                        'obligation_ids' => 'One of the intentions is no longer accepted.',
                    ]);
                }

                $storedReason = $this->resolveDateVarianceReason($request, $celebration, $batchReason);

                MassIntentionAssignment::query()->create([
                    'tenant_id' => $tenantId,
                    'obligation_id' => $obligation->id,
                    'celebration_id' => $celebrationId,
                    'assigned_at' => now(),
                    'assigned_by_user_id' => $actor->id,
                    'date_variance_reason' => $storedReason,
                ]);

                $obligation->status = MassObligationStatus::SCHEDULED;
                $obligation->save();
                $assigned++;
            }
        });

        return $assigned;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPendingForScheduling(int $tenantId, int $limit = 200): array
    {
        return MassIntentionObligation::query()
            ->where('tenant_id', $tenantId)
            ->where('status', MassObligationStatus::PENDING)
            ->whereHas('request', function ($q) use ($tenantId): void {
                $q->where('tenant_id', $tenantId)
                    ->where('status', MassIntentionStatus::ACCEPTED);
            })
            ->with('request:id,beneficiary_name,intention_text,requested_date,date_must_be_kept')
            ->orderBy('sequence')
            ->limit($limit)
            ->get()
            ->map(function (MassIntentionObligation $obligation) {
                $request = $obligation->request;

                return [
                    'obligation_id' => $obligation->id,
                    'request_id' => $obligation->request_id,
                    'sequence' => (int) $obligation->sequence,
                    'beneficiary_name' => $request?->beneficiary_name,
                    'intention_text' => $request?->intention_text,
                    'requested_date' => $request?->requested_date?->format('Y-m-d'),
                    'date_must_be_kept' => (bool) ($request?->date_must_be_kept ?? false),
                ];
            })
            ->all();
    }

    private function resolveScheduledCelebration(int $tenantId, string $celebrationId): MassCelebration
    {
        return MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $celebrationId)
            ->where('status', 'scheduled')
            ->firstOrFail();
    }

    private function dateVarianceRequired(MassIntentionRequest $request, MassCelebration $celebration): bool
    {
        if (! $request->date_must_be_kept || $request->requested_date === null) {
            return false;
        }

        return $request->requested_date->toDateString() !== $celebration->celebrated_on?->toDateString();
    }

    private function resolveDateVarianceReason(
        MassIntentionRequest $request,
        MassCelebration $celebration,
        ?string $dateVarianceReason
    ): ?string {
        if (! $this->dateVarianceRequired($request, $celebration)) {
            return null;
        }

        $reason = trim((string) $dateVarianceReason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'date_variance_reason' => 'The Mass is on a different day. Say why this date is acceptable.',
            ]);
        }

        return $reason;
    }
}
