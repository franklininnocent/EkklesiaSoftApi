<?php

namespace Modules\Family\app\Services;

use Modules\Family\app\Exceptions\HouseholdTransitionException;
use Modules\Family\app\Repositories\FamilyRepository;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;

class HouseholdSuccessionService
{
    public function __construct(
        protected FamilyRepository $familyRepository,
        protected HouseholdTransitionHistoryRecorder $historyRecorder,
    ) {}

    /**
     * @param  list<string>  $departingMemberIds
     * @param  list<array{origin_family_id: string, replacement_head_member_id?: string|null}>  $originSuccessions
     * @return array{mode: string, replacement_member_id?: string, family_status?: string}
     */
    public function resolveSuccessionForOriginFamily(
        string $familyId,
        array $departingMemberIds,
        array $originSuccessions,
        int|string $tenantId,
        int|string $userId,
        string $transitionId,
    ): array {
        $family = Family::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($familyId)
            ->first();

        if ($family === null) {
            throw HouseholdTransitionException::notFound(
                HouseholdTransitionException::FAMILY_NOT_FOUND,
                'Origin family not found.'
            );
        }

        if ($family->status === 'migrated') {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::FAMILY_ALREADY_MIGRATED,
                'Migrated families cannot participate in household transitions.'
            );
        }

        $succession = collect($originSuccessions)
            ->firstWhere('origin_family_id', $familyId);

        $remaining = FamilyMember::query()
            ->where('family_id', $familyId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereNotIn('id', $departingMemberIds)
            ->get();

        $count = $remaining->count();

        if ($count === 0) {
            $family->status = 'inactive';
            $family->head_of_family = null;
            $family->updated_by = $userId;
            $family->save();

            return ['mode' => 'inactive', 'family_status' => 'inactive'];
        }

        if ($count === 1) {
            $sole = $remaining->first();
            $previousRole = (string) $sole->relationship_to_head;

            $this->familyRepository->updateMember($sole, [
                'relationship_to_head' => 'self',
                'updated_by' => $userId,
            ]);

            $this->familyRepository->syncHeadOfFamily($familyId);

            $this->historyRecorder->record(
                $tenantId,
                $transitionId,
                (string) $sole->id,
                $familyId,
                $familyId,
                $previousRole,
                'self',
                'SUCCESSION',
                now()->toDateString(),
                $userId,
                ['succession_mode' => 'automatic', 'reason' => 'sole_remaining_member'],
            );

            return ['mode' => 'automatic', 'replacement_member_id' => (string) $sole->id];
        }

        $replacementId = $succession['replacement_head_member_id'] ?? null;
        if ($replacementId === null || $replacementId === '') {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::HEAD_SUCCESSION_REQUIRED,
                'A replacement head of household is required when multiple members remain.',
                ['origin_successions' => ['replacement_head_member_id is required for this origin family.']]
            );
        }

        if (in_array((string) $replacementId, $departingMemberIds, true)) {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::INVALID_SUCCESSION_MEMBER,
                'Replacement head cannot be a departing member.'
            );
        }

        $replacement = $remaining->firstWhere('id', $replacementId);
        if ($replacement === null) {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::INVALID_SUCCESSION_MEMBER,
                'Replacement head must be an active member of the origin family.'
            );
        }

        $previousRole = (string) $replacement->relationship_to_head;

        FamilyMember::query()
            ->where('family_id', $familyId)
            ->whereIn('relationship_to_head', ['self', 'head'])
            ->where('status', 'active')
            ->where('id', '!=', $replacementId)
            ->update(['relationship_to_head' => 'other']);

        $this->familyRepository->updateMember($replacement, [
            'relationship_to_head' => 'self',
            'updated_by' => $userId,
        ]);

        $this->familyRepository->syncHeadOfFamily($familyId);

        $this->historyRecorder->record(
            $tenantId,
            $transitionId,
            (string) $replacement->id,
            $familyId,
            $familyId,
            $previousRole,
            'self',
            'SUCCESSION',
            now()->toDateString(),
            $userId,
            ['succession_mode' => 'explicit'],
        );

        return ['mode' => 'explicit', 'replacement_member_id' => (string) $replacement->id];
    }
}
