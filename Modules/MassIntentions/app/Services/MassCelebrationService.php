<?php

namespace Modules\MassIntentions\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassIntentionAssignment;
use Modules\MassIntentions\Models\MassIntentionObligation;
use Modules\MassIntentions\Support\MassObligationStatus;

class MassCelebrationService
{
    public function __construct(
        private readonly MassIntentionAuditService $audits,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $tenantId, string $celebrationId, array $data): MassCelebration
    {
        $celebration = MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $celebrationId)
            ->where('status', 'scheduled')
            ->firstOrFail();

        $celebration->fill([
            'celebrated_on' => $data['celebrated_on'],
            'celebrated_at' => $data['celebrated_at'] ?? null,
            'place' => $data['place'] ?? null,
            'celebrant_name' => $data['celebrant_name'] ?? null,
        ]);
        $celebration->save();

        return $celebration->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $tenantId, User $actor, array $data): MassCelebration
    {
        return MassCelebration::query()->create([
            'tenant_id' => $tenantId,
            'celebrated_on' => $data['celebrated_on'],
            'celebrated_at' => $data['celebrated_at'] ?? null,
            'place' => $data['place'] ?? null,
            'celebrant_name' => $data['celebrant_name'] ?? null,
            'status' => 'scheduled',
            'created_by_user_id' => $actor->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(MassCelebration $celebration): array
    {
        return [
            'id' => $celebration->id,
            'celebrated_on' => $celebration->celebrated_on?->format('Y-m-d'),
            'celebrated_at' => $celebration->celebrated_at,
            'place' => $celebration->place,
            'celebrant_name' => $celebration->celebrant_name,
            'status' => $celebration->status,
        ];
    }

    /**
     * Cancel a Mass and release intentions (not scheduled) unless reassigned.
     *
     * @param  list<array{obligation_id: string, celebration_id?: string|null}>  $reassignments
     */
    public function cancel(
        int $tenantId,
        User $actor,
        string $celebrationId,
        string $reason,
        array $reassignments = []
    ): MassCelebration {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Enter why this Mass is cancelled.',
            ]);
        }

        $celebration = MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $celebrationId)
            ->where('status', 'scheduled')
            ->firstOrFail();

        $reassignMap = [];
        foreach ($reassignments as $item) {
            if (! empty($item['obligation_id'])) {
                $reassignMap[$item['obligation_id']] = $item['celebration_id'] ?? null;
            }
        }

        return DB::transaction(function () use ($tenantId, $actor, $celebration, $reason, $reassignMap): MassCelebration {
            $assignments = MassIntentionAssignment::query()
                ->where('tenant_id', $tenantId)
                ->where('celebration_id', $celebration->id)
                ->whereNull('unassigned_at')
                ->get();

            foreach ($assignments as $assignment) {
                $assignment->unassigned_at = now();
                $assignment->save();

                $obligation = MassIntentionObligation::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $assignment->obligation_id)
                    ->first();

                if (! $obligation || $obligation->status === MassObligationStatus::SAID) {
                    continue;
                }

                $targetCelebration = $reassignMap[$obligation->id] ?? null;

                if ($targetCelebration) {
                    $exists = MassCelebration::query()
                        ->where('tenant_id', $tenantId)
                        ->where('id', $targetCelebration)
                        ->where('status', 'scheduled')
                        ->exists();

                    if (! $exists) {
                        throw ValidationException::withMessages([
                            'reassignments' => 'One of the chosen Masses is no longer available.',
                        ]);
                    }

                    MassIntentionAssignment::query()->create([
                        'tenant_id' => $tenantId,
                        'obligation_id' => $obligation->id,
                        'celebration_id' => $targetCelebration,
                        'assigned_at' => now(),
                        'assigned_by_user_id' => $actor->id,
                    ]);
                    $obligation->status = MassObligationStatus::SCHEDULED;
                } else {
                    $obligation->status = MassObligationStatus::PENDING;
                }

                $obligation->save();
            }

            $celebration->status = 'cancelled';
            $celebration->cancel_reason = $reason;
            $celebration->save();

            $this->audits->record($tenantId, 'celebration.cancelled', $actor, null, $celebration->id, [
                'reason' => $reason,
            ]);

            return $celebration->fresh();
        });
    }
}
