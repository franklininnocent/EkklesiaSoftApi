<?php

namespace Modules\MassIntentions\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Models\MassIntentionAssignment;
use Modules\MassIntentions\Models\MassIntentionFulfilment;
use Modules\MassIntentions\Models\MassIntentionObligation;
use Modules\MassIntentions\Support\MassObligationStatus;

class MassIntentionFulfilmentService
{
    /**
     * @param  list<string>  $obligationIds
     */
    /**
     * @param  list<string>  $obligationIds
     * @param  array<string, string|null>  $celebrantOverrides
     */
    public function confirmSaid(
        int $tenantId,
        User $actor,
        string $celebrationId,
        array $obligationIds,
        array $celebrantOverrides = []
    ): int {
        if ($obligationIds === []) {
            throw ValidationException::withMessages([
                'obligation_ids' => 'Select at least one intention that was said.',
            ]);
        }

        $confirmed = 0;

        DB::transaction(function () use ($tenantId, $actor, $celebrationId, $obligationIds, $celebrantOverrides, &$confirmed): void {
            foreach ($obligationIds as $obligationId) {
                $obligation = MassIntentionObligation::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $obligationId)
                    ->first();

                if (! $obligation || $obligation->status === MassObligationStatus::SAID) {
                    continue;
                }

                $assigned = MassIntentionAssignment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('obligation_id', $obligationId)
                    ->where('celebration_id', $celebrationId)
                    ->whereNull('unassigned_at')
                    ->exists();

                if (! $assigned) {
                    throw ValidationException::withMessages([
                        'obligation_ids' => 'One or more intentions are not scheduled for this Mass.',
                    ]);
                }

                $override = isset($celebrantOverrides[$obligationId])
                    ? trim((string) $celebrantOverrides[$obligationId])
                    : '';
                $override = $override !== '' ? $override : null;

                MassIntentionFulfilment::query()->create([
                    'tenant_id' => $tenantId,
                    'obligation_id' => $obligationId,
                    'celebration_id' => $celebrationId,
                    'fulfilled_at' => now(),
                    'fulfilled_by_user_id' => $actor->id,
                    'celebrant_override' => $override,
                ]);

                $obligation->status = MassObligationStatus::SAID;
                $obligation->save();
                $confirmed++;
            }
        });

        return $confirmed;
    }

    public function undo(int $tenantId, User $actor, string $fulfilmentId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Enter a short reason.',
            ]);
        }

        DB::transaction(function () use ($tenantId, $fulfilmentId, $reason): void {
            $fulfilment = MassIntentionFulfilment::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $fulfilmentId)
                ->whereNull('undone_at')
                ->firstOrFail();

            $fulfilment->undone_at = now();
            $fulfilment->undo_reason = $reason;
            $fulfilment->save();

            $obligation = MassIntentionObligation::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $fulfilment->obligation_id)
                ->first();

            if ($obligation) {
                $obligation->status = MassObligationStatus::SCHEDULED;
                $obligation->save();
            }
        });
    }
}
