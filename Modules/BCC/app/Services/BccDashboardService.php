<?php

namespace Modules\BCC\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BCCLeader;
use Modules\BCC\Models\BccAuditLog;
use Modules\BCC\Models\BccFamilyMembership;
use Modules\BCC\Support\BccAgeBands;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;

class BccDashboardService
{
    /**
     * @param  array{
     *   status?: string|null,
     *   period?: string|null,
     *   search?: string|null,
     *   coordinator?: string|null,
     *   attention?: string|null,
     *   trend?: string|null,
     *   overview_limit?: int|null
     * }  $filters
     */
    public function summary(int $tenantId, array $filters = []): array
    {
        $status = $this->normalizeStatus($filters['status'] ?? null);
        $period = $this->normalizePeriod($filters['period'] ?? null);
        $search = trim((string) ($filters['search'] ?? ''));
        $coordinatorId = $filters['coordinator'] ?? null;
        $attention = $filters['attention'] ?? null;
        $trend = $filters['trend'] ?? null;
        $overviewLimit = max(5, min(50, (int) ($filters['overview_limit'] ?? 10)));

        [$periodStart, $periodEnd] = $this->periodBounds($period);
        $priorStart = $periodStart->copy()->sub($periodStart->diffAsCarbonInterval($periodEnd));
        $priorEnd = $periodStart->copy()->subDay()->endOfDay();

        $bccQuery = BCC::query()->forTenant((string) $tenantId);
        if ($status !== null) {
            $bccQuery->where('status', $status);
        }
        if ($coordinatorId) {
            $bccQuery->whereHas('activeLeaders', function ($q) use ($coordinatorId) {
                $q->where('family_member_id', $coordinatorId)
                    ->whereIn('role', ['leader', 'coordinator']);
            });
        }
        $scopedBccIds = (clone $bccQuery)->pluck('id');

        $totalBccs = (clone $bccQuery)->count();
        $activeBccs = (clone $bccQuery)->where('status', 'active')->count();
        $inactiveBccs = (clone $bccQuery)->where('status', 'inactive')->count();
        $suspendedBccs = (clone $bccQuery)->where('status', 'suspended')->count();

        // Snapshot coverage is parish-wide (status/coordinator do not shrink parish family totals).
        $parishFamilies = Family::query()->where('tenant_id', $tenantId)->count();
        $familiesInBcc = Family::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('bcc_id')
            ->when($scopedBccIds->isNotEmpty() && ($status !== null || $coordinatorId), function ($q) use ($scopedBccIds) {
                $q->whereIn('bcc_id', $scopedBccIds);
            })
            ->count();

        // Unlinked is always parish-wide (families with no BCC).
        $familiesWithout = Family::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('bcc_id')
            ->count();

        // When filtering BCCs by status/coordinator, "linked" for coverage bar uses scoped in-BCC;
        // parish coverage % still uses all linked + unlinked.
        $allLinked = Family::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('bcc_id')
            ->count();
        $coverageDenom = $allLinked + $familiesWithout;
        $coveragePercent = $coverageDenom > 0
            ? round(($allLinked / $coverageDenom) * 100, 1)
            : null;

        $memberBase = FamilyMember::query()
            ->join('families', 'families.id', '=', 'family_members.family_id')
            ->where('families.tenant_id', $tenantId)
            ->whereNotNull('families.bcc_id')
            ->whereNull('families.deleted_at')
            ->whereNull('family_members.deleted_at')
            ->when($scopedBccIds->isNotEmpty() && ($status !== null || $coordinatorId), function ($q) use ($scopedBccIds) {
                $q->whereIn('families.bcc_id', $scopedBccIds);
            });

        $totalMembers = (clone $memberBase)->count();
        $activeMembers = (clone $memberBase)->where('family_members.status', 'active')->count();
        $averageBccSize = $totalBccs > 0 ? round($totalMembers / $totalBccs, 1) : null;

        $activeLeaders = BCCLeader::query()
            ->where('is_active', true)
            ->whereHas('bcc', function ($q) use ($tenantId, $scopedBccIds, $status, $coordinatorId) {
                $q->where('tenant_id', $tenantId);
                if ($scopedBccIds->isNotEmpty() && ($status !== null || $coordinatorId)) {
                    $q->whereIn('id', $scopedBccIds);
                }
            })
            ->count();

        $activeInScope = BCC::query()
            ->forTenant((string) $tenantId)
            ->where('status', 'active')
            ->when($scopedBccIds->isNotEmpty() && ($status !== null || $coordinatorId), fn ($q) => $q->whereIn('id', $scopedBccIds));

        $withoutPrimary = (clone $activeInScope)->whereDoesntHave('primaryLeader')->count();
        $withPrimary = (clone $activeInScope)->whereHas('primaryLeader')->count();
        $activeCountForCoverage = (clone $activeInScope)->count();
        $leadershipCoverage = $activeCountForCoverage > 0
            ? round(($withPrimary / $activeCountForCoverage) * 100, 1)
            : null;

        $emptyBccs = BCC::query()
            ->forTenant((string) $tenantId)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($coordinatorId, function ($q) use ($coordinatorId) {
                $q->whereHas('activeLeaders', function ($lq) use ($coordinatorId) {
                    $lq->where('family_member_id', $coordinatorId)
                        ->whereIn('role', ['leader', 'coordinator']);
                });
            })
            ->whereDoesntHave('families')
            ->count();

        $dataQuality = $this->buildDataQuality($tenantId, $allLinked, $familiesWithout, $withoutPrimary);
        $demographics = $this->buildDemographics($tenantId, $scopedBccIds, $status, $coordinatorId);
        $growth = $this->buildGrowthSeries($tenantId, $periodStart, $periodEnd);
        $communityChanges = $this->buildCommunityChanges($tenantId, $scopedBccIds, $periodStart, $priorStart, $priorEnd);
        $overview = $this->buildCommunityOverview(
            $tenantId,
            $scopedBccIds,
            $status,
            $coordinatorId,
            $search,
            $attention,
            $trend,
            $communityChanges,
            $overviewLimit
        );
        $sizeDistribution = $this->buildSizeDistribution($tenantId, $scopedBccIds, $status, $coordinatorId);

        $coveragePointChange = $this->coveragePointChange(
            $tenantId,
            $allLinked,
            $familiesWithout,
            $periodStart
        );

        $withoutPrimaryList = BCC::query()
            ->forTenant((string) $tenantId)
            ->where('status', 'active')
            ->whereDoesntHave('primaryLeader')
            ->when($scopedBccIds->isNotEmpty() && ($status !== null || $coordinatorId), fn ($q) => $q->whereIn('id', $scopedBccIds))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'bcc_code'])
            ->map(fn (BCC $bcc) => [
                'id' => $bcc->id,
                'name' => $bcc->name,
                'bcc_code' => $bcc->bcc_code,
            ])
            ->values();

        $topBccs = BCC::query()
            ->forTenant((string) $tenantId)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->withCount('families')
            ->orderByDesc('families_count')
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'bcc_code', 'status'])
            ->map(fn (BCC $bcc) => [
                'id' => $bcc->id,
                'name' => $bcc->name,
                'bcc_code' => $bcc->bcc_code,
                'status' => $bcc->status,
                'family_count' => $bcc->families_count,
            ])
            ->values();

        $recent = BccAuditLog::query()
            ->forTenant($tenantId)
            ->with(['actor:id,name,email', 'bcc:id,name,bcc_code'])
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (BccAuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'bcc_id' => $log->bcc_id,
                'bcc_name' => $log->bcc?->name,
                'actor_name' => $log->actor?->name,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->values();

        $attentionItems = $this->buildAttention(
            $withoutPrimary,
            $familiesWithout,
            $emptyBccs,
            $dataQuality['review_total']
        );

        $insights = $this->buildInsights(
            $coveragePercent,
            $coveragePointChange,
            $allLinked,
            $familiesWithout,
            $topBccs,
            $withoutPrimary,
            $emptyBccs
        );

        $coordinators = $this->buildCoordinatorOptions($tenantId);

        $snapshot = [
            'bccs_total' => $totalBccs,
            'bccs_active' => $activeBccs,
            'bccs_inactive' => $inactiveBccs,
            'bccs_suspended' => $suspendedBccs,
            'families_connected' => $allLinked,
            'families_connected_scoped' => $familiesInBcc,
            'people_connected' => $totalMembers,
            'active_members' => $activeMembers,
            'parish_families' => $parishFamilies,
            'families_without_bcc' => $familiesWithout,
            'coverage_percent' => $coveragePercent,
            'coverage_percent_point_change' => $coveragePointChange,
            'average_bcc_size' => $averageBccSize,
            'leadership_coverage_percent' => $leadershipCoverage,
            'active_leaders' => $activeLeaders,
            'bccs_without_primary' => $withoutPrimary,
            'bccs_with_primary' => $withPrimary,
            'empty_bccs' => $emptyBccs,
        ];

        return [
            // Legacy keys (existing clients / tests)
            'bccs' => [
                'total' => $totalBccs,
                'active' => $activeBccs,
                'inactive' => $inactiveBccs,
                'suspended' => $suspendedBccs,
            ],
            'families' => [
                'in_bcc' => $allLinked,
                'without_bcc' => $familiesWithout,
            ],
            'members' => [
                'total' => $totalMembers,
                'active' => $activeMembers,
            ],
            'leadership' => [
                'active_leaders' => $activeLeaders,
                'bccs_without_primary' => $withoutPrimary,
                'bccs_with_primary' => $withPrimary,
                'coverage_percent' => $leadershipCoverage,
                'without_primary_list' => $withoutPrimaryList,
            ],
            'top_bccs' => $topBccs,
            'bccs_without_primary_leader' => $withoutPrimaryList,
            'recent_activity' => $recent,

            // Pastoral intelligence payload
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'status' => $status ?? 'all',
                'period' => $period,
                'search' => $search !== '' ? $search : null,
                'coordinator' => $coordinatorId,
                'attention' => $attention,
                'trend' => $trend,
            ],
            'definitions' => $this->definitions(),
            'snapshot' => $snapshot,
            'coverage' => [
                'linked' => $allLinked,
                'unlinked' => $familiesWithout,
                'total' => $coverageDenom,
                'percent' => $coveragePercent,
                'percent_point_change' => $coveragePointChange,
            ],
            'growth' => $growth,
            'community_changes' => $communityChanges,
            'insights' => $insights,
            'attention' => $attentionItems,
            'community_overview' => $overview,
            'size_distribution' => $sizeDistribution,
            'demographics' => $demographics,
            'data_quality' => $dataQuality,
            'coordinators' => $coordinators,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function definitions(): array
    {
        return [
            'coverage' => 'Percentage of parish families with a BCC assignment (families.bcc_id is set).',
            'people' => 'Family members in families currently assigned to a BCC.',
            'active_members' => 'BCC-connected family members whose status is active in the family register.',
            'family_growth' => 'Change in BCC-connected family counts over the selected period.',
            'people_growth' => 'Change in BCC-connected people counts over the selected period.',
            'leadership_coverage' => 'Share of active BCCs that have a primary leader (role = leader).',
            'life_stage' => 'Derived from date of birth using EkklesiaSoft BCC age bands (babies 0–2, children 3–12, teenagers 13–17, young adults 18–25, adults 26–59, seniors 60+).',
            'data_quality' => 'Share of reviewed records without common completeness issues (missing address, missing phone on BCC-linked members, missing primary leader on active BCCs).',
            'coverage_point_change' => 'Difference in coverage percentage compared with the start of the selected period, expressed in percentage points.',
        ];
    }

    private function normalizeStatus(?string $status): ?string
    {
        $status = $status === null || $status === '' || $status === 'all' ? null : strtolower($status);

        return in_array($status, ['active', 'inactive', 'suspended'], true) ? $status : null;
    }

    private function normalizePeriod(?string $period): string
    {
        $period = strtolower((string) $period);

        return in_array($period, ['3m', '6m', '1y', '3y', '12m'], true)
            ? ($period === '12m' ? '1y' : $period)
            : '1y';
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodBounds(string $period): array
    {
        $end = now()->endOfDay();
        $start = match ($period) {
            '3m' => now()->subMonths(3)->startOfDay(),
            '6m' => now()->subMonths(6)->startOfDay(),
            '3y' => now()->subYears(3)->startOfDay(),
            default => now()->subYear()->startOfDay(),
        };

        return [$start, $end];
    }

    private function buildDataQuality(int $tenantId, int $linkedFamilies, int $unlinked, int $withoutPrimary): array
    {
        $missingAddress = Family::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) {
                $q->whereNull('address_line_1')->orWhere('address_line_1', '');
            })
            ->count();

        $missingPhone = FamilyMember::query()
            ->join('families', 'families.id', '=', 'family_members.family_id')
            ->where('families.tenant_id', $tenantId)
            ->whereNotNull('families.bcc_id')
            ->whereNull('families.deleted_at')
            ->whereNull('family_members.deleted_at')
            ->where('family_members.status', 'active')
            ->where(function ($q) {
                $q->whereNull('family_members.phone')->orWhere('family_members.phone', '');
            })
            ->count();

        $reviewTotal = $missingAddress + $missingPhone + $withoutPrimary + $unlinked;
        $denominator = max(1, $linkedFamilies + $unlinked + $withoutPrimary);
        $issueWeight = min($reviewTotal, $denominator);
        $completeness = round((1 - ($issueWeight / max($denominator, 1))) * 100, 1);

        return [
            'completeness_percent' => $completeness,
            'review_total' => $reviewTotal,
            'issues' => [
                'missing_address' => $missingAddress,
                'missing_phone' => $missingPhone,
                'missing_primary_leader' => $withoutPrimary,
                'unassigned_families' => $unlinked,
            ],
        ];
    }

    /**
     * @param  Collection<int, string>  $scopedBccIds
     */
    private function buildDemographics(int $tenantId, Collection $scopedBccIds, ?string $status, $coordinatorId): array
    {
        $memberBase = FamilyMember::query()
            ->join('families', 'families.id', '=', 'family_members.family_id')
            ->where('families.tenant_id', $tenantId)
            ->whereNotNull('families.bcc_id')
            ->whereNull('families.deleted_at')
            ->whereNull('family_members.deleted_at')
            ->when($scopedBccIds->isNotEmpty() && ($status !== null || $coordinatorId), function ($q) use ($scopedBccIds) {
                $q->whereIn('families.bcc_id', $scopedBccIds);
            });

        $total = (clone $memberBase)->count();

        $genderCounts = (clone $memberBase)
            ->select([
                DB::raw("COALESCE(family_members.gender, 'unknown') as gender_key"),
                DB::raw('COUNT(*) as aggregate'),
            ])
            ->groupBy(DB::raw("COALESCE(family_members.gender, 'unknown')"))
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

        $gender = [];
        foreach (['male', 'female', 'other', 'unknown'] as $key) {
            $count = (int) ($genderCounts[$key] ?? 0);
            $gender[$key] = [
                'count' => $count,
                'percent' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
            ];
        }

        $ageGroups = [];
        foreach (BccAgeBands::definitions() as $band) {
            $count = (int) ($ageCounts[$band['key']] ?? 0);
            $ageGroups[$band['key']] = [
                'label' => $band['label'],
                'min' => $band['min'],
                'max' => $band['max'],
                'count' => $count,
                'percent' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
            ];
        }

        return [
            'total' => $total,
            'gender' => $gender,
            'age_groups' => $ageGroups,
        ];
    }

    /**
     * @return array{period: string, insufficient_history: bool, families: list<array{period: string, label: string, value: int}>, people: list<array{period: string, label: string, value: int}>}
     */
    private function buildGrowthSeries(int $tenantId, Carbon $periodStart, Carbon $periodEnd): array
    {
        $months = [];
        $cursor = $periodStart->copy()->startOfMonth();
        $endMonth = $periodEnd->copy()->startOfMonth();
        while ($cursor <= $endMonth) {
            $months[] = $cursor->copy();
            $cursor->addMonth();
        }

        if (count($months) < 2) {
            return [
                'period' => $periodStart->toDateString().'_'.$periodEnd->toDateString(),
                'insufficient_history' => true,
                'families' => [],
                'people' => [],
            ];
        }

        $memberships = BccFamilyMembership::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->get(['family_id', 'joined_date', 'exit_date', 'is_current', 'status']);

        $useMemberships = $memberships->isNotEmpty();

        $familiesSeries = [];
        $peopleSeries = [];
        $anyNonZero = false;

        foreach ($months as $month) {
            $asOf = $month->copy()->endOfMonth();
            if ($asOf->gt($periodEnd)) {
                $asOf = $periodEnd->copy();
            }

            if ($useMemberships) {
                $familyIds = $memberships
                    ->filter(function ($m) use ($asOf) {
                        $joined = $m->joined_date ? Carbon::parse($m->joined_date)->startOfDay() : null;
                        if ($joined === null || $joined->gt($asOf)) {
                            return false;
                        }
                        if ($m->exit_date) {
                            return Carbon::parse($m->exit_date)->startOfDay()->gt($asOf);
                        }

                        return true;
                    })
                    ->pluck('family_id')
                    ->unique()
                    ->values();
                $familyCount = $familyIds->count();
                $peopleCount = $familyCount === 0 ? 0 : FamilyMember::query()
                    ->whereIn('family_id', $familyIds)
                    ->whereNull('deleted_at')
                    ->count();
            } else {
                $familyCount = Family::query()
                    ->where('tenant_id', $tenantId)
                    ->whereNotNull('bcc_id')
                    ->where('created_at', '<=', $asOf)
                    ->count();
                $peopleCount = FamilyMember::query()
                    ->join('families', 'families.id', '=', 'family_members.family_id')
                    ->where('families.tenant_id', $tenantId)
                    ->whereNotNull('families.bcc_id')
                    ->where('families.created_at', '<=', $asOf)
                    ->whereNull('families.deleted_at')
                    ->whereNull('family_members.deleted_at')
                    ->count();
            }

            if ($familyCount > 0 || $peopleCount > 0) {
                $anyNonZero = true;
            }

            $key = $month->format('Y-m');
            $label = $month->format('M Y');
            $familiesSeries[] = ['period' => $key, 'label' => $label, 'value' => $familyCount];
            $peopleSeries[] = ['period' => $key, 'label' => $label, 'value' => $peopleCount];
        }

        return [
            'period' => $periodStart->toDateString().'_'.$periodEnd->toDateString(),
            'insufficient_history' => ! $anyNonZero,
            'families' => $familiesSeries,
            'people' => $peopleSeries,
        ];
    }

    /**
     * Approximate coverage at period start using memberships joined before start.
     */
    private function coveragePointChange(int $tenantId, int $linkedNow, int $unlinkedNow, Carbon $periodStart): ?float
    {
        $denomNow = $linkedNow + $unlinkedNow;
        if ($denomNow === 0) {
            return null;
        }

        $memberships = BccFamilyMembership::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->count();

        if ($memberships === 0) {
            return null;
        }

        $asOf = $periodStart->copy()->subDay()->endOfDay();
        $linkedThen = BccFamilyMembership::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($asOf) {
                $q->whereNotNull('joined_date')->where('joined_date', '<=', $asOf->toDateString());
            })
            ->where(function ($q) use ($asOf) {
                $q->whereNull('exit_date')->orWhere('exit_date', '>', $asOf->toDateString());
            })
            ->distinct('family_id')
            ->count('family_id');

        // Approximate parish size then ≈ current unlinked + linked (stable denom); use current denom for pp change.
        $percentThen = round(($linkedThen / $denomNow) * 100, 1);
        $percentNow = round(($linkedNow / $denomNow) * 100, 1);

        return round($percentNow - $percentThen, 1);
    }

    /**
     * @param  Collection<int, string>  $scopedBccIds
     * @return array{growing: list<array<string, mixed>>, declining: list<array<string, mixed>>, deltas: array<string, array{from: int, to: int, delta: int, delta_pct: float|null}>}
     */
    private function buildCommunityChanges(
        int $tenantId,
        Collection $scopedBccIds,
        Carbon $periodStart,
        Carbon $priorStart,
        Carbon $priorEnd
    ): array {
        unset($priorStart, $priorEnd);

        $bccs = BCC::query()
            ->forTenant((string) $tenantId)
            ->when($scopedBccIds->isNotEmpty(), fn ($q) => $q->whereIn('id', $scopedBccIds))
            ->withCount('families')
            ->get(['id', 'name', 'bcc_code', 'status']);

        $deltas = [];
        foreach ($bccs as $bcc) {
            $to = (int) $bcc->families_count;
            $from = $this->familiesAsOf($tenantId, $bcc->id, $periodStart->copy()->subDay());
            $delta = $to - $from;
            $deltaPct = $from > 0 ? round(($delta / $from) * 100, 1) : ($to > 0 && $from === 0 ? 100.0 : null);
            $deltas[$bcc->id] = [
                'id' => $bcc->id,
                'name' => $bcc->name,
                'bcc_code' => $bcc->bcc_code,
                'status' => $bcc->status,
                'from' => $from,
                'to' => $to,
                'delta' => $delta,
                'delta_pct' => $deltaPct,
            ];
        }

        $growing = collect($deltas)
            ->filter(fn ($d) => $d['delta'] > 0)
            ->sortByDesc('delta')
            ->take(5)
            ->values()
            ->all();

        $declining = collect($deltas)
            ->filter(fn ($d) => $d['delta'] < 0)
            ->sortBy('delta')
            ->take(5)
            ->values()
            ->all();

        return [
            'growing' => $growing,
            'declining' => $declining,
            'deltas' => $deltas,
        ];
    }

    private function familiesAsOf(int $tenantId, string $bccId, Carbon $asOf): int
    {
        $count = BccFamilyMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('bcc_id', $bccId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($asOf) {
                $q->whereNotNull('joined_date')->where('joined_date', '<=', $asOf->toDateString());
            })
            ->where(function ($q) use ($asOf) {
                $q->whereNull('exit_date')->orWhere('exit_date', '>', $asOf->toDateString());
            })
            ->distinct('family_id')
            ->count('family_id');

        if ($count > 0) {
            return $count;
        }

        // Fallback when membership history is thin: treat current assignment as unchanged.
        return Family::query()
            ->where('tenant_id', $tenantId)
            ->where('bcc_id', $bccId)
            ->where('created_at', '<=', $asOf)
            ->count();
    }

    /**
     * @param  Collection<int, string>  $scopedBccIds
     * @param  array{growing: list, declining: list, deltas: array}  $communityChanges
     */
    private function buildCommunityOverview(
        int $tenantId,
        Collection $scopedBccIds,
        ?string $status,
        $coordinatorId,
        string $search,
        ?string $attention,
        ?string $trend,
        array $communityChanges,
        int $limit
    ): array {
        $query = BCC::query()
            ->forTenant((string) $tenantId)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($coordinatorId, function ($q) use ($coordinatorId) {
                $q->whereHas('activeLeaders', function ($lq) use ($coordinatorId) {
                    $lq->where('family_member_id', $coordinatorId)
                        ->whereIn('role', ['leader', 'coordinator']);
                });
            })
            ->when($search !== '', function ($q) use ($search) {
                $like = BccAgeBands::likeOperator();
                $term = '%'.$search.'%';
                $q->where(function ($inner) use ($like, $term) {
                    $inner->where('name', $like, $term)
                        ->orWhere('bcc_code', $like, $term);
                });
            })
            ->withCount('families')
            ->with(['primaryLeader.member']);

        if ($attention === 'no_primary') {
            $query->where('status', 'active')->whereDoesntHave('primaryLeader');
        } elseif ($attention === 'empty') {
            $query->whereDoesntHave('families');
        }

        $all = $query->orderByDesc('families_count')->orderBy('name')->get();

        if ($trend === 'growing') {
            $growingIds = collect($communityChanges['growing'])->pluck('id')->all();
            $all = $all->filter(fn (BCC $b) => in_array($b->id, $growingIds, true))->values();
        } elseif ($trend === 'declining') {
            $decliningIds = collect($communityChanges['declining'])->pluck('id')->all();
            $all = $all->filter(fn (BCC $b) => in_array($b->id, $decliningIds, true))->values();
        }

        $peopleByBcc = FamilyMember::query()
            ->join('families', 'families.id', '=', 'family_members.family_id')
            ->where('families.tenant_id', $tenantId)
            ->whereNotNull('families.bcc_id')
            ->whereNull('families.deleted_at')
            ->whereNull('family_members.deleted_at')
            ->selectRaw('families.bcc_id, COUNT(*) as people_count')
            ->groupBy('families.bcc_id')
            ->pluck('people_count', 'bcc_id');

        $rows = $all->take($limit)->map(function (BCC $bcc) use ($communityChanges, $peopleByBcc) {
            $delta = $communityChanges['deltas'][$bcc->id] ?? null;
            $flags = [];
            if ($bcc->status === 'active' && ! $bcc->primaryLeader) {
                $flags[] = 'no_primary';
            }
            if ((int) $bcc->families_count === 0) {
                $flags[] = 'empty';
            }

            return [
                'id' => $bcc->id,
                'name' => $bcc->name,
                'bcc_code' => $bcc->bcc_code,
                'families' => (int) $bcc->families_count,
                'people' => (int) ($peopleByBcc[$bcc->id] ?? 0),
                'trend_pct' => $delta['delta_pct'] ?? null,
                'trend_delta' => $delta['delta'] ?? null,
                'primary_leader_name' => $bcc->primaryLeader?->member?->full_name_display,
                'status' => $bcc->status,
                'attention_flags' => $flags,
            ];
        })->values();

        return [
            'rows' => $rows,
            'limit' => $limit,
            'total_matching' => $all->count(),
            'label' => 'Top '.$limit.' by families',
        ];
    }

    /**
     * @param  Collection<int, string>  $scopedBccIds
     */
    private function buildSizeDistribution(int $tenantId, Collection $scopedBccIds, ?string $status, $coordinatorId): array
    {
        $peopleByBcc = FamilyMember::query()
            ->join('families', 'families.id', '=', 'family_members.family_id')
            ->join('bccs', 'bccs.id', '=', 'families.bcc_id')
            ->where('families.tenant_id', $tenantId)
            ->whereNull('families.deleted_at')
            ->whereNull('family_members.deleted_at')
            ->whereNull('bccs.deleted_at')
            ->when($status !== null, fn ($q) => $q->where('bccs.status', $status))
            ->when($scopedBccIds->isNotEmpty() && ($status !== null || $coordinatorId), fn ($q) => $q->whereIn('bccs.id', $scopedBccIds))
            ->selectRaw('families.bcc_id, COUNT(*) as people_count')
            ->groupBy('families.bcc_id')
            ->pluck('people_count', 'bcc_id');

        $buckets = [
            '1-10' => 0,
            '11-25' => 0,
            '26-50' => 0,
            '51-100' => 0,
            '100+' => 0,
            '0' => 0,
        ];

        $bccIds = BCC::query()
            ->forTenant((string) $tenantId)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($scopedBccIds->isNotEmpty() && ($status !== null || $coordinatorId), fn ($q) => $q->whereIn('id', $scopedBccIds))
            ->pluck('id');

        foreach ($bccIds as $id) {
            $n = (int) ($peopleByBcc[$id] ?? 0);
            if ($n === 0) {
                $buckets['0']++;
            } elseif ($n <= 10) {
                $buckets['1-10']++;
            } elseif ($n <= 25) {
                $buckets['11-25']++;
            } elseif ($n <= 50) {
                $buckets['26-50']++;
            } elseif ($n <= 100) {
                $buckets['51-100']++;
            } else {
                $buckets['100+']++;
            }
        }

        return collect($buckets)
            ->map(fn ($count, $bucket) => ['bucket' => $bucket, 'label' => $bucket === '0' ? 'No people yet' : $bucket.' people', 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * @return list<array{type: string, severity: string, count: int, label: string}>
     */
    private function buildAttention(int $withoutPrimary, int $unlinked, int $empty, int $reviewTotal): array
    {
        $items = [];
        if ($withoutPrimary > 0) {
            $items[] = [
                'type' => 'no_primary',
                'severity' => 'critical',
                'count' => $withoutPrimary,
                'label' => 'BCCs without a primary leader',
            ];
        }
        if ($unlinked > 0) {
            $items[] = [
                'type' => 'unlinked_families',
                'severity' => 'attention',
                'count' => $unlinked,
                'label' => 'Families without a BCC',
            ];
        }
        if ($empty > 0) {
            $items[] = [
                'type' => 'empty',
                'severity' => 'attention',
                'count' => $empty,
                'label' => 'BCCs with no families',
            ];
        }
        if ($reviewTotal > 0) {
            $items[] = [
                'type' => 'data_review',
                'severity' => 'information',
                'count' => $reviewTotal,
                'label' => 'Records requiring data review',
            ];
        }

        return $items;
    }

    /**
     * @param  Collection<int, array<string, mixed>>|array  $topBccs
     * @return list<array{id: string, severity: string, text: string, reasons?: list<string>}>
     */
    private function buildInsights(
        ?float $coveragePercent,
        ?float $coveragePointChange,
        int $linked,
        int $unlinked,
        $topBccs,
        int $withoutPrimary,
        int $empty
    ): array {
        $insights = [];

        if ($coveragePointChange !== null && abs($coveragePointChange) >= 0.1) {
            $dir = $coveragePointChange >= 0 ? 'increased' : 'decreased';
            $insights[] = [
                'id' => 'coverage_change',
                'severity' => 'information',
                'text' => sprintf(
                    'Coverage %s by %s percentage points.',
                    $dir,
                    number_format(abs($coveragePointChange), 1)
                ),
            ];
        }

        $top = collect($topBccs);
        if ($linked > 0 && $top->isNotEmpty()) {
            $top3 = $top->take(3)->sum('family_count');
            $share = round(($top3 / $linked) * 100, 1);
            if ($share >= 30) {
                $insights[] = [
                    'id' => 'concentration',
                    'severity' => 'information',
                    'text' => sprintf(
                        '%d BCCs account for %s%% of connected families.',
                        min(3, $top->count()),
                        number_format($share, 1)
                    ),
                ];
            }
        }

        if ($unlinked > 0) {
            $insights[] = [
                'id' => 'unlinked',
                'severity' => 'attention',
                'text' => sprintf('%d families currently have no BCC assignment.', $unlinked),
            ];
        }

        if ($withoutPrimary > 0) {
            $insights[] = [
                'id' => 'leadership',
                'severity' => 'critical',
                'text' => sprintf('%d BCCs require leadership review.', $withoutPrimary),
            ];
        }

        if ($empty > 0) {
            $insights[] = [
                'id' => 'empty',
                'severity' => 'attention',
                'text' => sprintf('%d BCCs have no families assigned yet.', $empty),
            ];
        }

        if ($coveragePercent !== null && $unlinked === 0 && $linked > 0) {
            $insights[] = [
                'id' => 'full_coverage',
                'severity' => 'information',
                'text' => 'All parish families are currently connected to a BCC.',
            ];
        }

        return $insights;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function buildCoordinatorOptions(int $tenantId): array
    {
        return BCCLeader::query()
            ->where('is_active', true)
            ->whereIn('role', ['leader', 'coordinator'])
            ->whereHas('bcc', fn ($q) => $q->where('tenant_id', $tenantId))
            ->with('member')
            ->get()
            ->map(fn (BCCLeader $leader) => [
                'id' => $leader->family_member_id,
                'name' => $leader->member?->full_name_display ?? 'Leader',
            ])
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->all();
    }
}
