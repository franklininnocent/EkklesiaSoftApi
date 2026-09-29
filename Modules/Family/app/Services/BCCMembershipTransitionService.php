<?php

namespace Modules\Family\app\Services;

use Illuminate\Support\Facades\DB;
use Modules\BCC\Models\BccFamilyMembership;
use Modules\BCC\Services\BccFamilyMembershipService;
use Modules\Family\app\Exceptions\HouseholdTransitionException;
use Modules\Family\Models\Family;

class BCCMembershipTransitionService
{
    public const REASON_RELOCATION = 'RELOCATION';

    public const REASON_MARRIAGE = 'MARRIAGE';

    public const REASON_SPLIT = 'SPLIT';

    public const REASON_ASSIGN = 'ASSIGN';

    public function __construct(
        protected BccFamilyMembershipService $bccFamilyMembershipService,
    ) {}

    /**
     * @return array{mode: string, membership: BccFamilyMembership}
     */
    public function transferFamilyToBcc(
        int $tenantId,
        Family $family,
        string $targetBccId,
        string $effectiveDate,
        string $transferReason,
        string $transitionId,
        int|string $userId,
        ?string $historicalNote = null,
    ): array {
        $this->assertEffectiveDate($effectiveDate);

        return DB::transaction(function () use (
            $tenantId,
            $family,
            $targetBccId,
            $effectiveDate,
            $transferReason,
            $transitionId,
            $userId,
            $historicalNote
        ): array {
            $lockedFamily = Family::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($family->id)
                ->lockForUpdate()
                ->first();

            if ($lockedFamily === null) {
                throw HouseholdTransitionException::notFound(
                    HouseholdTransitionException::FAMILY_NOT_FOUND,
                    'Family not found.'
                );
            }

            $currentMembership = BccFamilyMembership::query()
                ->forTenant($tenantId)
                ->current()
                ->where('family_id', $lockedFamily->id)
                ->lockForUpdate()
                ->first();

            $currentBccId = $currentMembership?->bcc_id ?? $lockedFamily->bcc_id;

            if ($currentBccId === $targetBccId) {
                throw HouseholdTransitionException::validation(
                    HouseholdTransitionException::TARGET_BCC_ALREADY_ASSIGNED,
                    'This family is already assigned to the target BCC.'
                );
            }

            $mode = $currentBccId ? self::REASON_RELOCATION : self::REASON_ASSIGN;

            if ($currentBccId) {
                $this->validateNoIntervalOverlap(
                    $tenantId,
                    (string) $lockedFamily->id,
                    $effectiveDate,
                    $currentMembership?->id
                );
            }

            $membership = $this->bccFamilyMembershipService->transferFamilyToBcc(
                $tenantId,
                $lockedFamily,
                $targetBccId,
                $effectiveDate,
                $transferReason,
                $transitionId,
                (int) $userId,
                $historicalNote,
                $currentBccId !== null,
            );

            return ['mode' => $mode, 'membership' => $membership];
        });
    }

    private function assertEffectiveDate(string $effectiveDate): void
    {
        if ($effectiveDate > now()->toDateString()) {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::INVALID_EFFECTIVE_DATE,
                'Effective date cannot be in the future.'
            );
        }
    }

    private function validateNoIntervalOverlap(
        int $tenantId,
        string $familyId,
        string $effectiveDate,
        ?string $excludeMembershipId = null,
    ): void {
        $intervals = BccFamilyMembership::query()
            ->forTenant($tenantId)
            ->where('family_id', $familyId)
            ->when($excludeMembershipId, fn ($q) => $q->where('id', '!=', $excludeMembershipId))
            ->get();

        foreach ($intervals as $interval) {
            $start = $interval->joined_date?->format('Y-m-d');
            $end = $interval->exit_date?->format('Y-m-d') ?? now()->toDateString();

            if ($start === null) {
                continue;
            }

            if ($effectiveDate >= $start && $effectiveDate <= $end) {
                throw HouseholdTransitionException::validation(
                    HouseholdTransitionException::INTERVAL_OVERLAP,
                    'Effective date overlaps an existing BCC membership interval.',
                    ['effective_date' => ['Choose a date outside existing membership intervals.']]
                );
            }
        }
    }
}
