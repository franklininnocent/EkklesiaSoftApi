<?php

namespace Modules\MinistriesAssociations\Services;

use Illuminate\Support\Facades\DB;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\OrganizationMembership;

class MinistriesCensusCascadeService
{
    public const MEMBERSHIP_EXIT_REASON = 'Auto-exited due to Family Status Change';

    /** @var list<string> */
    public const CASCADE_STATUSES = [
        'inactive',
        'deceased',
        'transferred',
        'deleted',
    ];

    public function __construct(private readonly MinistriesAuditService $auditService) {}

    public function shouldProcess(string $previousStatus, string $newStatus): bool
    {
        if (! in_array($newStatus, self::CASCADE_STATUSES, true)) {
            return false;
        }

        $normalizedPrevious = $this->normalizeStatus($previousStatus);

        if ($normalizedPrevious === $newStatus) {
            return false;
        }

        if (in_array($normalizedPrevious, self::CASCADE_STATUSES, true)) {
            return false;
        }

        return true;
    }

    /**
     * @return array{memberships_exited: int, leadership_vacated: int}
     */
    public function cascade(
        int $tenantId,
        string $familyMemberId,
        string $previousStatus,
        string $newStatus,
        string $effectiveDate,
    ): array {
        if (! $this->shouldProcess($previousStatus, $newStatus)) {
            return [
                'memberships_exited' => 0,
                'leadership_vacated' => 0,
            ];
        }

        return DB::transaction(function () use (
            $tenantId,
            $familyMemberId,
            $previousStatus,
            $newStatus,
            $effectiveDate,
        ): array {
            $memberships = OrganizationMembership::query()
                ->forTenant($tenantId)
                ->where('family_member_id', $familyMemberId)
                ->where('member_source', OrganizationMembership::SOURCE_PARISH)
                ->where('is_current', true)
                ->lockForUpdate()
                ->get();

            $membershipIds = $memberships->pluck('id')->all();

            $leadershipTerms = $membershipIds === []
                ? collect()
                : LeadershipTerm::query()
                    ->forTenant($tenantId)
                    ->where('status', LeadershipTerm::STATUS_ACTIVE)
                    ->whereIn('membership_id', $membershipIds)
                    ->lockForUpdate()
                    ->get();

            $metadata = [
                'family_member_id' => $familyMemberId,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'effective_date' => $effectiveDate,
            ];

            $leadershipVacated = 0;

            foreach ($leadershipTerms as $term) {
                $oldValues = $this->leadershipAuditSnapshot($term);

                $effectiveTo = $effectiveDate;
                if ($term->effective_from !== null && $effectiveTo < $term->effective_from->toDateString()) {
                    $effectiveTo = $term->effective_from->toDateString();
                }

                $term->update([
                    'status' => LeadershipTerm::STATUS_VACATED,
                    'exit_reason' => LeadershipTerm::EXIT_REASON_CENSUS_CASCADE,
                    'effective_to' => $effectiveTo,
                ]);
                $term->refresh();

                $this->auditService->log(
                    $tenantId,
                    'census_cascade.leadership_vacated',
                    'leadership_term',
                    $term->id,
                    $oldValues,
                    $this->leadershipAuditSnapshot($term),
                    $term->organization_id,
                    $metadata,
                );

                $leadershipVacated++;
            }

            $membershipsExited = 0;

            foreach ($memberships as $membership) {
                $oldValues = $this->membershipAuditSnapshot($membership);

                $exitDate = $effectiveDate;
                if ($membership->joined_date !== null && $exitDate < $membership->joined_date->toDateString()) {
                    $exitDate = $membership->joined_date->toDateString();
                }

                $membership->update([
                    'status' => OrganizationMembership::STATUS_EXITED,
                    'exit_reason' => self::MEMBERSHIP_EXIT_REASON,
                    'exit_date' => $exitDate,
                    'is_current' => false,
                ]);
                $membership->refresh();

                $this->auditService->log(
                    $tenantId,
                    'census_cascade.membership_exited',
                    'membership',
                    $membership->id,
                    $oldValues,
                    $this->membershipAuditSnapshot($membership),
                    $membership->organization_id,
                    $metadata,
                );

                $membershipsExited++;
            }

            return [
                'memberships_exited' => $membershipsExited,
                'leadership_vacated' => $leadershipVacated,
            ];
        });
    }

    private function normalizeStatus(string $status): string
    {
        return $status === 'migrated' ? 'transferred' : $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipAuditSnapshot(OrganizationMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'organization_id' => $membership->organization_id,
            'member_source' => $membership->member_source,
            'family_member_id' => $membership->family_member_id,
            'status' => $membership->status,
            'joined_date' => $membership->joined_date?->toDateString(),
            'exit_date' => $membership->exit_date?->toDateString(),
            'exit_reason' => $membership->exit_reason,
            'is_current' => $membership->is_current,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leadershipAuditSnapshot(LeadershipTerm $term): array
    {
        return [
            'id' => $term->id,
            'organization_id' => $term->organization_id,
            'membership_id' => $term->membership_id,
            'position_id' => $term->position_id,
            'status' => $term->status,
            'effective_from' => $term->effective_from?->toDateString(),
            'effective_to' => $term->effective_to?->toDateString(),
            'exit_reason' => $term->exit_reason,
        ];
    }
}
