<?php

namespace Modules\Family\app\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Family\app\Repositories\FamilyRepository;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;

class FamilySplitService
{
    public function __construct(
        protected FamilyRepository $familyRepository,
        protected FamilyAuditService $familyAuditService,
        protected HouseholdTransitionHistoryRecorder $historyRecorder,
        protected BCCMembershipTransitionService $bccTransitionService,
        protected HouseholdSuccessionService $successionService,
    ) {}

    /**
     * @param  array<string, mixed>  $newFamilyData
     * @param  array<string, mixed>  $memberOverrides
     * @return array{source_family: Family, new_family: Family, member: FamilyMember}
     */
    public function splitMember(
        string $familyId,
        string $memberId,
        array $newFamilyData,
        array $memberOverrides,
        int|string $tenantId,
        int|string $userId
    ): array {
        $sourceFamily = $this->familyRepository->findById($familyId, (string) $tenantId);
        if (! $sourceFamily) {
            throw ValidationException::withMessages([
                'family_id' => 'Family not found in this parish.',
            ]);
        }

        $member = $this->familyRepository->findMemberById($memberId, $familyId);
        if (! $member || $member->status !== 'active') {
            throw ValidationException::withMessages([
                'member_id' => 'Active family member not found.',
            ]);
        }

        $transitionId = (string) ($newFamilyData['transition_id'] ?? Str::uuid());
        $effectiveDate = (string) ($newFamilyData['effective_date'] ?? now()->toDateString());
        $originSuccessions = $newFamilyData['origin_successions'] ?? [];
        $wasHead = in_array(strtolower((string) $member->relationship_to_head), ['self', 'head'], true);
        $previousRole = (string) $member->relationship_to_head;

        return DB::transaction(function () use (
            $sourceFamily,
            $member,
            $newFamilyData,
            $memberOverrides,
            $tenantId,
            $userId,
            $transitionId,
            $effectiveDate,
            $wasHead,
            $previousRole,
            $familyId,
            $memberId,
            $originSuccessions
        ) {
            $newFamilyData['tenant_id'] = $tenantId;
            $newFamilyData['created_by'] = $userId;
            $newFamilyData['updated_by'] = $userId;
            $targetBccId = $newFamilyData['bcc_id'] ?? null;
            unset($newFamilyData['members'], $newFamilyData['transition_id'], $newFamilyData['effective_date'], $newFamilyData['origin_successions']);

            $newFamily = $this->familyRepository->create($newFamilyData);

            $memberOverrides['family_id'] = $newFamily->id;
            $memberOverrides['relationship_to_head'] = $memberOverrides['relationship_to_head'] ?? 'self';
            $memberOverrides['updated_by'] = $userId;

            $this->familyRepository->updateMember($member, $memberOverrides);
            $member->refresh();

            $this->historyRecorder->record(
                $tenantId,
                $transitionId,
                $memberId,
                $familyId,
                $newFamily->id,
                $previousRole,
                (string) $member->relationship_to_head,
                'SPLIT',
                $effectiveDate,
                $userId,
            );

            if ($wasHead) {
                $this->successionService->resolveSuccessionForOriginFamily(
                    $familyId,
                    [$memberId],
                    $originSuccessions,
                    $tenantId,
                    $userId,
                    $transitionId,
                );
            }

            $this->familyRepository->syncHeadOfFamily($newFamily->id);
            $this->familyRepository->syncHeadOfFamily($sourceFamily->id);

            if ($targetBccId) {
                $this->bccTransitionService->transferFamilyToBcc(
                    (int) $tenantId,
                    $newFamily->fresh(),
                    (string) $targetBccId,
                    $effectiveDate,
                    BCCMembershipTransitionService::REASON_SPLIT,
                    $transitionId,
                    $userId,
                );
            }

            try {
                $this->familyAuditService->log(
                    (int) $tenantId,
                    'family.split',
                    'family',
                    (string) $newFamily->id,
                    ['source_family_id' => $sourceFamily->id],
                    ['member_id' => $member->id, 'person_id' => $member->person_id, 'transition_id' => $transitionId],
                );
            } catch (\Throwable $e) {
                Log::warning('Family audit log failed on split', ['error' => $e->getMessage()]);
            }

            return [
                'source_family' => $this->familyRepository->findById($sourceFamily->id, (string) $tenantId),
                'new_family' => $this->familyRepository->findById($newFamily->id, (string) $tenantId),
                'member' => $member,
            ];
        });
    }
}
