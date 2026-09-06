<?php

namespace Modules\Family\app\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Family\Models\FamilyMemberHistory;

class HouseholdTransitionHistoryRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        int|string $tenantId,
        string $transitionId,
        string $memberId,
        ?string $fromFamilyId,
        ?string $toFamilyId,
        ?string $previousRole,
        ?string $newRole,
        string $transitionType,
        string $effectiveDate,
        int|string|null $userId,
        array $metadata = [],
        ?string $correctsHistoryId = null,
    ): FamilyMemberHistory {
        return FamilyMemberHistory::create([
            'tenant_id' => $tenantId,
            'transition_id' => $transitionId,
            'member_id' => $memberId,
            'from_family_id' => $fromFamilyId,
            'to_family_id' => $toFamilyId,
            'previous_family_role' => $previousRole,
            'new_family_role' => $newRole,
            'transition_type' => $transitionType,
            'effective_date' => $effectiveDate,
            'performed_by_user_id' => $userId,
            'corrects_history_id' => $correctsHistoryId,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * @return Collection<int, FamilyMemberHistory>
     */
    public function forFamily(int|string $tenantId, string $familyId)
    {
        return FamilyMemberHistory::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($familyId): void {
                $q->where('from_family_id', $familyId)
                    ->orWhere('to_family_id', $familyId);
            })
            ->orderByDesc('effective_date')
            ->orderByDesc('created_at')
            ->with(['member:id,first_name,last_name', 'performer:id,name'])
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function correct(
        array $data,
        int|string $tenantId,
        int|string $userId,
    ): FamilyMemberHistory {
        $metadata = $data['metadata'] ?? [];
        if (! empty($data['correction_note'])) {
            $metadata['correction_note'] = $data['correction_note'];
        }

        return $this->record(
            $tenantId,
            (string) $data['transition_id'],
            (string) $data['member_id'],
            $data['from_family_id'] ?? null,
            $data['to_family_id'] ?? null,
            $data['previous_family_role'] ?? null,
            $data['new_family_role'] ?? null,
            'ADMIN_CORRECTION',
            (string) $data['effective_date'],
            $userId,
            $metadata,
            (string) $data['corrects_history_id'],
        );
    }
}
