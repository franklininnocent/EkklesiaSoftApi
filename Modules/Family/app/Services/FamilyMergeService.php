<?php

namespace Modules\Family\app\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Family\app\Repositories\FamilyRepository;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;

class FamilyMergeService
{
    public function __construct(
        protected FamilyRepository $familyRepository,
        protected PersonService $personService,
        protected FamilyAuditService $familyAuditService,
    ) {}

    /**
     * @return array{target_family: Family, source_family_id: string, moved_member_ids: list<string>}
     */
    public function mergeFamilies(
        string $sourceFamilyId,
        string $targetFamilyId,
        int|string $tenantId,
        int|string $userId
    ): array {
        if ($sourceFamilyId === $targetFamilyId) {
            throw ValidationException::withMessages([
                'source_family_id' => 'Source and target families must be different.',
            ]);
        }

        $sourceFamily = $this->familyRepository->findById($sourceFamilyId, (string) $tenantId);
        $targetFamily = $this->familyRepository->findById($targetFamilyId, (string) $tenantId);

        if (! $sourceFamily || ! $targetFamily) {
            throw ValidationException::withMessages([
                'family_id' => 'One or both families were not found in this parish.',
            ]);
        }

        if ($sourceFamily->status !== 'active' || $targetFamily->status !== 'active') {
            throw ValidationException::withMessages([
                'status' => 'Both families must be active to merge.',
            ]);
        }

        $sourceMembers = FamilyMember::query()
            ->where('family_id', $sourceFamilyId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get();

        $targetPersonIds = FamilyMember::query()
            ->where('family_id', $targetFamilyId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->pluck('person_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->all();

        foreach ($sourceMembers as $member) {
            if ($member->person_id && in_array((string) $member->person_id, $targetPersonIds, true)) {
                throw ValidationException::withMessages([
                    'source_family_id' => 'Cannot merge: a person would belong to the target family twice.',
                ]);
            }
        }

        return DB::transaction(function () use ($sourceFamily, $targetFamily, $sourceMembers, $tenantId, $userId) {
            $movedIds = [];

            foreach ($sourceMembers as $member) {
                if ($member->person_id && $this->personService->hasActiveFamilyMembership((string) $member->person_id, excludeMemberId: (string) $member->id)) {
                    throw ValidationException::withMessages([
                        'source_family_id' => 'Cannot merge: a member already has an active family elsewhere.',
                    ]);
                }

                $this->familyRepository->updateMember($member, [
                    'family_id' => $targetFamily->id,
                    'updated_by' => $userId,
                ]);
                $movedIds[] = (string) $member->id;
            }

            $this->familyRepository->delete($sourceFamily);
            $this->familyRepository->syncHeadOfFamily($targetFamily->id);

            try {
                $this->familyAuditService->log(
                    (int) $tenantId,
                    'family.merged',
                    'family',
                    (string) $targetFamily->id,
                    ['source_family_id' => $sourceFamily->id],
                    ['moved_member_ids' => $movedIds],
                );
            } catch (\Throwable $e) {
                Log::warning('Family audit log failed on merge', ['error' => $e->getMessage()]);
            }

            return [
                'target_family' => $this->familyRepository->findById($targetFamily->id, (string) $tenantId),
                'source_family_id' => $sourceFamily->id,
                'moved_member_ids' => $movedIds,
            ];
        });
    }
}
