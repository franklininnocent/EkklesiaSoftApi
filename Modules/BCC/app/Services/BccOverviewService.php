<?php

namespace Modules\BCC\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\BCC\Models\BCCLeader;
use Modules\BCC\Models\BccAuditLog;
use Modules\BCC\Models\BccFamilyMembership;
use Modules\BCC\Support\BccAgeBands;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;

class BccOverviewService
{
    private const GROWTH_MONTHS = 12;

    private const DEMOGRAPHIC_DEFINITION = 'Complete means gender and date of birth are recorded.';

    public function __construct(private readonly BccFamilyMembershipService $membershipService)
    {
    }

    public function summary(int $tenantId, string $bccId): array
    {
        $bcc = $this->membershipService->findBcc($tenantId, $bccId);

        $memberBase = FamilyMember::query()
            ->select('family_members.*')
            ->join('families', 'families.id', '=', 'family_members.family_id')
            ->where('families.tenant_id', $tenantId)
            ->where('families.bcc_id', $bccId)
            ->whereNull('families.deleted_at')
            ->whereNull('family_members.deleted_at');

        $totalMembers = (clone $memberBase)->count();

        $statusCounts = (clone $memberBase)
            ->select('family_members.status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('family_members.status')
            ->pluck('aggregate', 'family_members.status');

        $genderCounts = (clone $memberBase)
            ->select([
                DB::raw("COALESCE(NULLIF(LOWER(TRIM(family_members.gender)), ''), 'unknown') as gender_key"),
                DB::raw('COUNT(*) as aggregate'),
            ])
            ->groupBy(DB::raw("COALESCE(NULLIF(LOWER(TRIM(family_members.gender)), ''), 'unknown')"))
            ->pluck('aggregate', 'gender_key');

        $ageYears = BccAgeBands::ageYearsSql();
        $ageSql = BccAgeBands::sqlCase(
            "CASE WHEN family_members.date_of_birth IS NULL THEN NULL ELSE {$ageYears} END"
        );

        $ageCounts = (clone $memberBase)
            ->select([
                DB::raw("{$ageSql} as age_band"),
                DB::raw('COUNT(*) as aggregate'),
            ])
            ->groupBy(DB::raw($ageSql))
            ->pluck('aggregate', 'age_band');

        $childrenBoys = (clone $memberBase)
            ->whereNotNull('family_members.date_of_birth')
            ->whereRaw(BccAgeBands::ageYearsSql().' BETWEEN 3 AND 12')
            ->where('family_members.gender', 'male')
            ->count();
        $childrenGirls = (clone $memberBase)
            ->whereNotNull('family_members.date_of_birth')
            ->whereRaw(BccAgeBands::ageYearsSql().' BETWEEN 3 AND 12')
            ->where('family_members.gender', 'female')
            ->count();
        $childrenTotal = (int) ($ageCounts[BccAgeBands::CHILDREN] ?? 0);

        $babyBoys = (clone $memberBase)
            ->whereNotNull('family_members.date_of_birth')
            ->whereRaw(BccAgeBands::ageYearsSql().' BETWEEN 0 AND 2')
            ->where('family_members.gender', 'male')
            ->count();
        $babyGirls = (clone $memberBase)
            ->whereNotNull('family_members.date_of_birth')
            ->whereRaw(BccAgeBands::ageYearsSql().' BETWEEN 0 AND 2')
            ->where('family_members.gender', 'female')
            ->count();
        $babiesTotal = (int) ($ageCounts[BccAgeBands::BABIES] ?? 0);

        $familySizes = Family::query()
            ->where('tenant_id', $tenantId)
            ->where('bcc_id', $bccId)
            ->withCount('members')
            ->get();

        $totalFamilies = $familySizes->count();
        $size1 = $familySizes->where('members_count', 1)->count();
        $size24 = $familySizes->filter(fn ($f) => $f->members_count >= 2 && $f->members_count <= 4)->count();
        $size5 = $familySizes->where('members_count', '>=', 5)->count();

        $leaders = BCCLeader::query()
            ->where('bcc_id', $bccId)
            ->where('is_active', true)
            ->with('member')
            ->get();

        $hasPrimary = $leaders->contains(fn ($l) => $l->role === 'leader');
        $primaryLeader = $leaders->firstWhere('role', 'leader')?->member?->full_name_display;

        $dataQuality = $this->buildDataQuality($memberBase, $totalMembers);
        $attention = $this->buildAttention(
            $bcc->status,
            $hasPrimary,
            $totalFamilies,
            $totalMembers,
            $dataQuality['incomplete_count']
        );

        $recent = BccAuditLog::query()
            ->forTenant($tenantId)
            ->forBcc($bccId)
            ->with('actor:id,name,email')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $activeMembers = (int) ($statusCounts['active'] ?? 0);
        $inactiveMembers = (int) ($statusCounts['inactive'] ?? 0);
        $deceasedMembers = (int) ($statusCounts['deceased'] ?? 0);
        $migratedMembers = (int) ($statusCounts['migrated'] ?? 0);

        $gender = $this->withPercents([
            'male' => (int) ($genderCounts['male'] ?? 0),
            'female' => (int) ($genderCounts['female'] ?? 0),
            'other' => (int) ($genderCounts['other'] ?? 0),
            'unknown' => (int) ($genderCounts['unknown'] ?? 0),
        ], $totalMembers);

        return [
            'bcc' => [
                'id' => $bcc->id,
                'name' => $bcc->name,
                'bcc_code' => $bcc->bcc_code,
                'status' => $bcc->status,
                'description' => $bcc->description,
                'meeting_day' => $bcc->meeting_day,
                'meeting_time' => $bcc->meeting_time,
                'meeting_place' => $bcc->meeting_place,
                'created_at' => $bcc->created_at?->toIso8601String(),
                'updated_at' => $bcc->updated_at?->toIso8601String(),
            ],
            'total_members' => $totalMembers,
            'total_families' => $totalFamilies,
            'active_members' => $activeMembers,
            'inactive_members' => $inactiveMembers,
            'gender' => $gender,
            'age_groups' => $this->ageGroupsPayload($ageCounts, $totalMembers),
            'children' => [
                'total' => $childrenTotal,
                'boys' => $childrenBoys,
                'girls' => $childrenGirls,
            ],
            'babies' => [
                'total' => $babiesTotal,
                'boys' => $babyBoys,
                'girls' => $babyGirls,
            ],
            'occupation' => null,
            'education' => null,
            'families' => [
                'total' => $totalFamilies,
                'size_1' => $size1,
                'size_2_to_4' => $size24,
                'size_5_plus' => $size5,
                'average_members' => $totalFamilies > 0
                    ? round($totalMembers / $totalFamilies, 1)
                    : 0,
            ],
            'membership_status' => [
                'active' => $activeMembers,
                'inactive' => $inactiveMembers,
                'deceased' => $deceasedMembers,
                'migrated' => $migratedMembers,
            ],
            'leadership' => [
                'active_count' => $leaders->count(),
                'has_primary' => $hasPrimary,
                'primary_leader' => $primaryLeader,
                'roles' => $leaders->groupBy('role')->map->count()->all(),
            ],
            'attention' => $attention,
            'data_quality' => $dataQuality,
            'growth' => $this->buildGrowthSeries($tenantId, $bccId),
            'recent_activity' => $recent->map(fn (BccAuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'target_type' => $log->target_type,
                'actor_name' => $log->actor?->name,
                'created_at' => $log->created_at?->toIso8601String(),
                'new_values' => $log->new_values,
            ])->values(),
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Modules\Family\Models\FamilyMember>  $memberBase
     * @return array{
     *   complete_count: int,
     *   incomplete_count: int,
     *   total: int,
     *   definition: string,
     *   missing_gender_count: int,
     *   missing_dob_count: int
     * }
     */
    private function buildDataQuality($memberBase, int $totalMembers): array
    {
        $completeCount = (clone $memberBase)
            ->whereNotNull('family_members.gender')
            ->whereRaw("LOWER(TRIM(family_members.gender)) NOT IN ('', 'unknown')")
            ->whereNotNull('family_members.date_of_birth')
            ->count();

        $missingGender = (clone $memberBase)
            ->where(function ($q) {
                $q->whereNull('family_members.gender')
                    ->orWhereRaw("LOWER(TRIM(family_members.gender)) IN ('', 'unknown')");
            })
            ->count();

        $missingDob = (clone $memberBase)
            ->whereNull('family_members.date_of_birth')
            ->count();

        return [
            'complete_count' => $completeCount,
            'incomplete_count' => max(0, $totalMembers - $completeCount),
            'total' => $totalMembers,
            'definition' => self::DEMOGRAPHIC_DEFINITION,
            'missing_gender_count' => $missingGender,
            'missing_dob_count' => $missingDob,
        ];
    }

    /**
     * @return list<array{code: string, severity: string, title: string, description: string, action: array{label: string, tab: string}}>
     */
    private function buildAttention(
        string $status,
        bool $hasPrimary,
        int $totalFamilies,
        int $totalMembers,
        int $incompleteCount
    ): array {
        $items = [];

        if ($status === 'active' && ! $hasPrimary) {
            $items[] = [
                'code' => 'no_primary',
                'severity' => 'warning',
                'title' => 'No primary leader assigned',
                'description' => 'A primary leader has not been assigned to this BCC.',
                'action' => [
                    'label' => 'View Leadership',
                    'tab' => 'leadership',
                ],
            ];
        }

        if ($totalFamilies === 0 && $totalMembers === 0) {
            $items[] = [
                'code' => 'empty',
                'severity' => 'info',
                'title' => 'This BCC has no members yet',
                'description' => 'Use the existing member and family assignment workflow to begin building this community.',
                'action' => [
                    'label' => 'View Members',
                    'tab' => 'members',
                ],
            ];
        }

        if ($incompleteCount > 0) {
            $noun = $incompleteCount === 1 ? 'member is' : 'members are';
            $items[] = [
                'code' => 'incomplete_demographics',
                'severity' => 'info',
                'title' => 'Member information incomplete',
                'description' => "{$incompleteCount} {$noun} missing required demographic information.",
                'action' => [
                    'label' => 'View Members',
                    'tab' => 'members',
                ],
            ];
        }

        return $items;
    }

    /**
     * End-of-month current membership counts for this BCC.
     * Each point is the number of families / people currently connected as of that month end
     * based on bcc_family_memberships interval rows (joined_date / exit_date).
     *
     * @return array{
     *   period: string,
     *   insufficient_history: bool,
     *   definition: string,
     *   members: list<array{period: string, label: string, value: int}>,
     *   families: list<array{period: string, label: string, value: int}>
     * }
     */
    private function buildGrowthSeries(int $tenantId, string $bccId): array
    {
        $periodEnd = Carbon::now()->endOfDay();
        $periodStart = Carbon::now()->subMonths(self::GROWTH_MONTHS - 1)->startOfMonth();

        $months = [];
        $cursor = $periodStart->copy()->startOfMonth();
        $endMonth = $periodEnd->copy()->startOfMonth();
        while ($cursor <= $endMonth) {
            $months[] = $cursor->copy();
            $cursor->addMonth();
        }

        $definition = 'Each monthly point is the end-of-month count of families and people currently connected to this BCC, reconstructed from membership joined/exit dates.';

        if (count($months) < 2) {
            return [
                'period' => $periodStart->toDateString().'_'.$periodEnd->toDateString(),
                'insufficient_history' => true,
                'definition' => $definition,
                'members' => [],
                'families' => [],
            ];
        }

        $memberships = BccFamilyMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('bcc_id', $bccId)
            ->whereNull('deleted_at')
            ->whereNotNull('joined_date')
            ->get(['family_id', 'joined_date', 'exit_date']);

        if ($memberships->isEmpty()) {
            return [
                'period' => $periodStart->toDateString().'_'.$periodEnd->toDateString(),
                'insufficient_history' => true,
                'definition' => $definition,
                'members' => [],
                'families' => [],
            ];
        }

        $familiesSeries = [];
        $membersSeries = [];
        $anyNonZero = false;

        foreach ($months as $month) {
            $asOf = $month->copy()->endOfMonth();
            if ($asOf->gt($periodEnd)) {
                $asOf = $periodEnd->copy();
            }
            $asOfDay = $asOf->copy()->startOfDay();

            $familyIds = $memberships
                ->filter(function ($m) use ($asOfDay) {
                    $joined = $m->joined_date
                        ? Carbon::parse($m->joined_date)->startOfDay()
                        : null;
                    if ($joined === null || $joined->gt($asOfDay)) {
                        return false;
                    }
                    if ($m->exit_date) {
                        return Carbon::parse($m->exit_date)->startOfDay()->gt($asOfDay);
                    }

                    return true;
                })
                ->pluck('family_id')
                ->unique()
                ->values();

            $familyCount = $familyIds->count();
            $peopleCount = $familyCount === 0
                ? 0
                : FamilyMember::query()
                    ->whereIn('family_id', $familyIds)
                    ->whereNull('deleted_at')
                    ->count();

            if ($familyCount > 0 || $peopleCount > 0) {
                $anyNonZero = true;
            }

            $key = $month->format('Y-m');
            $label = $month->format('M Y');
            $familiesSeries[] = ['period' => $key, 'label' => $label, 'value' => $familyCount];
            $membersSeries[] = ['period' => $key, 'label' => $label, 'value' => $peopleCount];
        }

        return [
            'period' => $periodStart->toDateString().'_'.$periodEnd->toDateString(),
            'insufficient_history' => ! $anyNonZero,
            'definition' => $definition,
            'members' => $membersSeries,
            'families' => $familiesSeries,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, array{count: int, percent: float}>
     */
    private function withPercents(array $counts, int $total): array
    {
        $out = [];
        foreach ($counts as $key => $count) {
            $out[$key] = [
                'count' => $count,
                'percent' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
            ];
        }

        return $out;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int>  $ageCounts
     */
    private function ageGroupsPayload($ageCounts, int $total): array
    {
        $groups = [];
        foreach (BccAgeBands::definitions() as $band) {
            $count = (int) ($ageCounts[$band['key']] ?? 0);
            $groups[$band['key']] = [
                'label' => $band['label'],
                'min' => $band['min'],
                'max' => $band['max'],
                'count' => $count,
                'percent' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
            ];
        }

        return $groups;
    }
}
