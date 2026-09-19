<?php

namespace Modules\Tenants\Services;

use Illuminate\Support\Facades\Schema;
use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BCCLeader;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\app\Services\FamilyService;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\Tenant;

/**
 * Authoritative Church Status metrics for the parish profile overview.
 *
 * Each metric returns either a percentage (0–100) or status "unavailable" when
 * the underlying data cannot support a meaningful calculation.
 */
class ChurchStatusMetricsService
{
    private const ABOUT_PLACEHOLDER_PREFIX = 'Add a parish narrative';

    public function __construct(
        private readonly FamilyService $familyService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $tenantId): array
    {
        $tenant = Tenant::query()
            ->with([
                'addresses',
                'churchProfile.denomination',
                'churchProfile.archdiocese',
            ])
            ->findOrFail($tenantId);

        $familyStats = $this->safeFamilyStatistics($tenantId);
        $membershipHealth = $this->buildMembershipHealth($familyStats);
        $sacramentalRecords = $this->buildSacramentalRecords($tenantId, $familyStats);
        $volunteerEngagement = $this->buildVolunteerEngagement($tenantId, $familyStats);
        $profileCompleteness = $this->buildProfileCompleteness($tenant, $tenant->churchProfile);

        return [
            'membership_health' => $membershipHealth,
            'sacramental_records' => $sacramentalRecords,
            'volunteer_engagement' => $volunteerEngagement,
            'profile_completeness' => $profileCompleteness,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $familyStats
     * @return array<string, mixed>
     */
    private function buildMembershipHealth(?array $familyStats): array
    {
        $totalMembers = (int) ($familyStats['total_members'] ?? 0);
        $activeMembers = (int) ($familyStats['active_members'] ?? 0);

        if ($totalMembers <= 0) {
            return $this->unavailableMetric(
                'membership_health',
                'No member records yet',
                'Add families and members to track active membership.',
                'Percentage of registered members marked active.',
                ['total_members' => 0, 'active_members' => 0]
            );
        }

        $percent = (int) min(100, max(0, round(($activeMembers / $totalMembers) * 100)));

        return $this->availableMetric(
            'membership_health',
            $percent,
            'Percentage of registered members marked active.',
            sprintf('%d of %d members are active.', $activeMembers, $totalMembers),
            ['total_members' => $totalMembers, 'active_members' => $activeMembers]
        );
    }

    /**
     * @param  array<string, mixed>|null  $familyStats
     * @return array<string, mixed>
     */
    private function buildSacramentalRecords(int $tenantId, ?array $familyStats): array
    {
        $totalMembers = (int) ($familyStats['total_members'] ?? 0);

        if ($totalMembers <= 0) {
            return $this->unavailableMetric(
                'sacramental_records',
                'No member records yet',
                'Sacramental coverage is calculated from parish members.',
                'Share of members with baptism, communion, confirmation, marriage, or register entries recorded.',
                ['total_members' => 0, 'members_with_records' => 0]
            );
        }

        $membersWithRecords = $this->countMembersWithSacramentalRecords($tenantId);
        $percent = (int) min(100, max(0, round(($membersWithRecords / $totalMembers) * 100)));

        return $this->availableMetric(
            'sacramental_records',
            $percent,
            'Share of members with baptism, communion, confirmation, marriage, or sacrament register entries recorded.',
            sprintf('%d of %d members have sacramental records.', $membersWithRecords, $totalMembers),
            [
                'total_members' => $totalMembers,
                'members_with_records' => $membersWithRecords,
            ]
        );
    }

    /**
     * @param  array<string, mixed>|null  $familyStats
     * @return array<string, mixed>
     */
    private function buildVolunteerEngagement(int $tenantId, ?array $familyStats): array
    {
        $totalFamilies = (int) ($familyStats['total_families'] ?? 0);
        $familiesWithBcc = (int) ($familyStats['families_with_bcc'] ?? 0);
        $totalMembers = (int) ($familyStats['total_members'] ?? 0);

        $activeBccs = BCC::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->count();

        $bccsWithLeader = BCC::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereHas('leaders', fn ($q) => $q->where('is_active', true))
            ->count();

        $activeLeaders = BCCLeader::query()
            ->whereHas('bcc', fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('is_active', true)
            ->count();

        $ministryMembers = $this->countActiveMinistryMembers($tenantId);

        if ($activeBccs === 0 && $activeLeaders === 0 && $ministryMembers === 0) {
            return $this->unavailableMetric(
                'volunteer_engagement',
                'No volunteer structure yet',
                'Create BCC groups or ministry memberships to track engagement.',
                'Blend of BCC leader coverage, family BCC assignment, and ministry participation.',
                [
                    'active_bccs' => 0,
                    'bccs_with_leader' => 0,
                    'families_with_bcc' => $familiesWithBcc,
                    'ministry_members' => 0,
                ]
            );
        }

        if ($totalFamilies <= 0 && $totalMembers <= 0) {
            return $this->unavailableMetric(
                'volunteer_engagement',
                'No families or members yet',
                'Add parish families before measuring volunteer engagement.',
                'Blend of BCC leader coverage, family BCC assignment, and ministry participation.',
                [
                    'active_bccs' => $activeBccs,
                    'bccs_with_leader' => $bccsWithLeader,
                    'families_with_bcc' => $familiesWithBcc,
                    'ministry_members' => $ministryMembers,
                ]
            );
        }

        $components = [];

        if ($activeBccs > 0) {
            $components[] = ($bccsWithLeader / $activeBccs) * 100;
        }

        if ($totalFamilies > 0) {
            $components[] = ($familiesWithBcc / $totalFamilies) * 100;
        }

        if ($ministryMembers > 0 && $totalMembers > 0) {
            $components[] = min(100, ($ministryMembers / $totalMembers) * 100);
        }

        $percent = (int) min(100, max(0, round(array_sum($components) / max(count($components), 1))));

        return $this->availableMetric(
            'volunteer_engagement',
            $percent,
            'Average of BCC leader coverage, families assigned to a BCC, and active ministry participation when available.',
            sprintf(
                '%d active BCC leader(s), %d of %d families in a BCC, %d ministry participant(s).',
                $activeLeaders,
                $familiesWithBcc,
                max($totalFamilies, 0),
                $ministryMembers
            ),
            [
                'active_bccs' => $activeBccs,
                'bccs_with_leader' => $bccsWithLeader,
                'families_with_bcc' => $familiesWithBcc,
                'total_families' => $totalFamilies,
                'ministry_members' => $ministryMembers,
                'active_leaders' => $activeLeaders,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProfileCompleteness(Tenant $tenant, ?ChurchProfile $profile): array
    {
        $officialAddress = $tenant->addresses
            ?->firstWhere('address_type', 'official');

        $about = trim((string) ($profile?->about ?? ''));
        $hasMeaningfulAbout = $about !== ''
            && ! str_starts_with($about, self::ABOUT_PLACEHOLDER_PREFIX);

        $checks = [
            'name' => filled($tenant->name),
            'logo' => filled($tenant->logo_url),
            'patron_name' => filled($profile?->patron_name),
            'denomination' => filled($profile?->denomination_id),
            'archdiocese' => filled($profile?->archdiocese_id),
            'founded_year' => filled($profile?->founded_year),
            'email' => filled($profile?->email) || filled($tenant->email),
            'phone' => filled($profile?->phone) || filled($tenant->phone),
            'website' => filled($profile?->website),
            'official_address' => $officialAddress !== null,
            'about' => $hasMeaningfulAbout,
        ];

        $filled = count(array_filter($checks));
        $total = count($checks);
        $percent = (int) min(100, max(0, round(($filled / max($total, 1)) * 100)));

        return $this->availableMetric(
            'profile_completeness',
            $percent,
            sprintf('%d of %d recommended church profile fields are completed.', $filled, $total),
            'Based on parish name, logo, patron, denomination, diocese, founded year, contact details, address, and narrative.',
            [
                'filled_fields' => $filled,
                'total_fields' => $total,
                'missing_fields' => array_keys(array_filter($checks, fn ($v) => ! $v)),
            ]
        );
    }

    private function countMembersWithSacramentalRecords(int $tenantId): int
    {
        $query = FamilyMember::query()
            ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId))
            ->where(function ($q) use ($tenantId) {
                $q->whereNotNull('baptism_date')
                    ->orWhereNotNull('first_communion_date')
                    ->orWhereNotNull('confirmation_date')
                    ->orWhereNotNull('marriage_date');

                if (Schema::hasTable('sacraments')) {
                    $q->orWhereIn('person_id', function ($sub) use ($tenantId) {
                        $sub->select('person_id')
                            ->from('sacraments')
                            ->where('tenant_id', $tenantId)
                            ->whereNull('deleted_at')
                            ->whereNotNull('person_id');
                    });
                }
            });

        return $query->count();
    }

    private function countActiveMinistryMembers(int $tenantId): int
    {
        if (! Schema::hasTable('ma_memberships')) {
            return 0;
        }

        return OrganizationMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->where('is_current', true)
            ->distinct('family_member_id')
            ->count('family_member_id');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeFamilyStatistics(int $tenantId): ?array
    {
        try {
            return $this->familyService->getStatistics((string) $tenantId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function availableMetric(
        string $key,
        int $percent,
        string $tooltip,
        string $summary,
        array $details = []
    ): array {
        return [
            'key' => $key,
            'label' => $this->labelForKey($key),
            'status' => 'available',
            'percent' => $percent,
            'display' => "{$percent}%",
            'tooltip' => $tooltip,
            'summary' => $summary,
            'details' => $details,
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function unavailableMetric(
        string $key,
        string $display,
        string $emptyMessage,
        string $tooltip,
        array $details = []
    ): array {
        return [
            'key' => $key,
            'label' => $this->labelForKey($key),
            'status' => 'unavailable',
            'percent' => null,
            'display' => 'Not available',
            'tooltip' => $tooltip,
            'empty_message' => $emptyMessage,
            'summary' => $emptyMessage,
            'details' => $details,
        ];
    }

    private function labelForKey(string $key): string
    {
        return match ($key) {
            'membership_health' => 'Membership Health',
            'sacramental_records' => 'Sacramental Records',
            'volunteer_engagement' => 'Volunteer Engagement',
            'profile_completeness' => 'Profile Completeness',
            default => ucwords(str_replace('_', ' ', $key)),
        };
    }
}
