<?php

namespace Modules\Family\app\Services;

use Illuminate\Support\Facades\DB;
use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BccFamilyMembership;
use Modules\BCC\Models\BCCLeader;
use Modules\Family\app\Exceptions\HouseholdTransitionException;
use Modules\Family\Events\FamilyBccRelocated;
use Modules\Family\Models\Family;
use Modules\Family\Models\HouseholdTransition;

class BccRelocationService
{
    public function __construct(
        protected BCCMembershipTransitionService $bccTransitionService,
        protected HouseholdTransitionHistoryRecorder $historyRecorder,
        protected FamilyAuditService $familyAuditService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function previewImpact(
        string $familyId,
        string $targetBccId,
        int|string $tenantId,
    ): array {
        $family = $this->findFamily($familyId, $tenantId);
        $this->assertFamilyEligible($family);

        $targetBcc = $this->findTargetBcc($targetBccId, $tenantId);
        $currentBccId = $this->currentBccId($family, $tenantId);

        if ($currentBccId === $targetBccId) {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::TARGET_BCC_ALREADY_ASSIGNED,
                'This family is already assigned to the target BCC.'
            );
        }

        $leadershipImpacts = $this->leadershipImpacts($tenantId, $currentBccId, $familyId);

        return [
            'family_id' => $family->id,
            'family_name' => $family->family_name,
            'member_count' => $family->members()->where('status', 'active')->count(),
            'current_bcc_id' => $currentBccId,
            'current_bcc_name' => $currentBccId ? BCC::query()->find($currentBccId)?->name : null,
            'target_bcc_id' => $targetBcc->id,
            'target_bcc_name' => $targetBcc->name,
            'transfer_mode' => $currentBccId ? BCCMembershipTransitionService::REASON_RELOCATION : BCCMembershipTransitionService::REASON_ASSIGN,
            'leadership_impacts' => $leadershipImpacts,
            'warnings' => $leadershipImpacts === [] ? [] : ['BCC leadership roles held by family members will be vacated.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transferFamily(
        string $familyId,
        string $targetBccId,
        string $effectiveDate,
        string $transitionId,
        int|string $tenantId,
        int|string $userId,
        ?string $historicalNote = null,
        ?string $requestHash = null,
    ): array {
        $existing = $this->findCompletedTransition($tenantId, $transitionId);
        if ($existing !== null) {
            return array_merge($existing->result_summary ?? [], [
                'code' => HouseholdTransitionException::TRANSITION_ALREADY_COMPLETED,
                'idempotent_replay' => true,
            ]);
        }

        return DB::transaction(function () use (
            $familyId,
            $targetBccId,
            $effectiveDate,
            $transitionId,
            $tenantId,
            $userId,
            $historicalNote,
            $requestHash
        ): array {
            $family = Family::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($familyId)
                ->lockForUpdate()
                ->first();

            if ($family === null) {
                throw HouseholdTransitionException::notFound(
                    HouseholdTransitionException::FAMILY_NOT_FOUND,
                    'Family not found.'
                );
            }

            $this->assertFamilyEligible($family);
            $targetBcc = $this->findTargetBcc($targetBccId, $tenantId);

            if ($targetBcc->status !== 'active') {
                throw HouseholdTransitionException::validation(
                    HouseholdTransitionException::TARGET_BCC_NOT_FOUND,
                    'Target BCC must be active.'
                );
            }

            $preview = $this->previewImpact($familyId, $targetBccId, $tenantId);
            $fromBccId = $preview['current_bcc_id'];

            $transfer = $this->bccTransitionService->transferFamilyToBcc(
                (int) $tenantId,
                $family->fresh(),
                $targetBccId,
                $effectiveDate,
                BCCMembershipTransitionService::REASON_RELOCATION,
                $transitionId,
                $userId,
                $historicalNote,
            );

            $activeMembers = $family->members()->where('status', 'active')->get();
            foreach ($activeMembers as $member) {
                $this->historyRecorder->record(
                    $tenantId,
                    $transitionId,
                    (string) $member->id,
                    $familyId,
                    $familyId,
                    (string) $member->relationship_to_head,
                    (string) $member->relationship_to_head,
                    'RELOCATION',
                    $effectiveDate,
                    $userId,
                    [
                        'from_bcc_id' => $fromBccId,
                        'to_bcc_id' => $targetBccId,
                        'transfer_mode' => $transfer['mode'],
                    ],
                );
            }

            $result = [
                'family_id' => $familyId,
                'from_bcc_id' => $fromBccId,
                'to_bcc_id' => $targetBccId,
                'transfer_mode' => $transfer['mode'],
                'membership_id' => $transfer['membership']->id,
                'leadership_impacts' => $preview['leadership_impacts'],
            ];

            HouseholdTransition::create([
                'tenant_id' => $tenantId,
                'transition_id' => $transitionId,
                'type' => HouseholdTransition::TYPE_RELOCATION,
                'status' => HouseholdTransition::STATUS_COMPLETED,
                'request_hash' => $requestHash,
                'result_summary' => $result,
                'performed_by_user_id' => $userId,
            ]);

            try {
                $this->familyAuditService->log(
                    (int) $tenantId,
                    'family.bcc_relocated',
                    'family',
                    $familyId,
                    ['from_bcc_id' => $fromBccId],
                    ['to_bcc_id' => $targetBccId, 'transition_id' => $transitionId],
                );
            } catch (\Throwable) {
                // audit failure must not roll back transition
            }

            FamilyBccRelocated::dispatch(
                (int) $tenantId,
                $familyId,
                $fromBccId,
                $targetBccId,
                $transitionId,
                $effectiveDate,
            );

            return $result;
        });
    }

    private function findFamily(string $familyId, int|string $tenantId): Family
    {
        $family = Family::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($familyId)
            ->withCount(['members as active_member_count' => fn ($q) => $q->where('status', 'active')])
            ->first();

        if ($family === null) {
            throw HouseholdTransitionException::notFound(
                HouseholdTransitionException::FAMILY_NOT_FOUND,
                'Family not found.'
            );
        }

        return $family;
    }

    private function assertFamilyEligible(Family $family): void
    {
        if ($family->status === 'migrated') {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::FAMILY_ALREADY_MIGRATED,
                'Migrated families cannot be relocated.'
            );
        }
    }

    private function findTargetBcc(string $bccId, int|string $tenantId): BCC
    {
        $bcc = BCC::query()->forTenant((string) $tenantId)->find($bccId);
        if ($bcc === null) {
            throw HouseholdTransitionException::notFound(
                HouseholdTransitionException::TARGET_BCC_NOT_FOUND,
                'Target BCC not found.'
            );
        }

        return $bcc;
    }

    private function currentBccId(Family $family, int|string $tenantId): ?string
    {
        $current = BccFamilyMembership::query()
            ->forTenant((int) $tenantId)
            ->current()
            ->where('family_id', $family->id)
            ->first();

        return $current?->bcc_id ?? $family->bcc_id;
    }

    /**
     * @return list<array{leader_id: string, member_name: string, role: string, role_label: string}>
     */
    private function leadershipImpacts(int|string $tenantId, ?string $bccId, string $familyId): array
    {
        if ($bccId === null) {
            return [];
        }

        return BCCLeader::query()
            ->where('bcc_id', $bccId)
            ->where('is_active', true)
            ->whereHas('member', fn ($q) => $q->where('family_id', $familyId))
            ->with('member:id,first_name,last_name')
            ->get()
            ->map(fn (BCCLeader $leader) => [
                'leader_id' => (string) $leader->id,
                'member_name' => trim(($leader->member?->first_name ?? '').' '.($leader->member?->last_name ?? '')),
                'role' => (string) $leader->role,
                'role_label' => ucfirst(str_replace('_', ' ', (string) $leader->role)),
            ])
            ->values()
            ->all();
    }

    private function findCompletedTransition(int|string $tenantId, string $transitionId): ?HouseholdTransition
    {
        return HouseholdTransition::query()
            ->where('tenant_id', $tenantId)
            ->where('transition_id', $transitionId)
            ->where('status', HouseholdTransition::STATUS_COMPLETED)
            ->first();
    }
}
