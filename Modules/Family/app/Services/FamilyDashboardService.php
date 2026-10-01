<?php

namespace Modules\Family\app\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\BCC\Support\BccAgeBands;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Family\Models\Family;
use Modules\Family\Services\ParishMemberMissingSacramentQuery;
use Modules\Family\Services\OccupationClassificationService;
use Modules\Family\Support\FamilyDashboardCache;
use Modules\Family\Support\FamilyQueryFilters;
use Modules\Family\Support\MemberEducationQualification;
use Modules\Family\Support\ParishProgressionFilter;
use Modules\Tenants\Services\ChurchCurrencyResolver;
use Throwable;

class FamilyDashboardService
{
    public function __construct(
        private readonly MemberAgeDemographicsService $ageDemographicsService,
        private readonly ParishMemberMissingSacramentQuery $sacramentQuery,
        private readonly ChurchCurrencyResolver $currencyResolver,
    ) {}

    /**
     * @param  array{bcc_id?: string|null, status?: string|null, period?: string, from?: string|null, to?: string|null, refresh?: bool}  $filters
     * @return array<string, mixed>
     */
    public function summary(int $tenantId, array $filters, bool $includeContributions, bool $includePastoral): array
    {
        $resolved = $this->resolveFilters($filters);
        $hash = md5((json_encode($resolved) ?: '').':occupation-categories-v2');
        $key = FamilyDashboardCache::payloadKey(
            $tenantId,
            FamilyDashboardCache::version($tenantId),
            $hash,
            $includeContributions,
            $includePastoral,
        );

        if (! ($filters['refresh'] ?? false)) {
            $cached = cache()->get($key);
            if (is_array($cached)) {
                $cached['meta']['cached'] = true;

                return $cached;
            }
        }

        $payload = $this->build($tenantId, $resolved, $includeContributions, $includePastoral);
        cache()->put($key, $payload, FamilyDashboardCache::TTL_SECONDS);

        return $payload;
    }

    /**
     * @param  array{bcc_id: ?string, status: ?string, period: string, from: string, to: string}  $filters
     * @return array<string, mixed>
     */
    private function build(int $tenantId, array $filters, bool $includeContributions, bool $includePastoral): array
    {
        $familyBase = $this->familyBase($tenantId, $filters['bcc_id'], $filters['status']);
        $memberBase = $this->ageDemographicsService->memberBaseQuery($tenantId, $filters['bcc_id'], $filters['status']);

        $totalFamilies = (clone $familyBase)->count();
        $familyStatusCounts = (clone $familyBase)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $activeFamilies = (int) ($familyStatusCounts['active'] ?? 0);
        $inactiveFamilies = (int) ($familyStatusCounts['inactive'] ?? 0);
        $migratedFamilies = (int) ($familyStatusCounts['migrated'] ?? 0);

        $memberStatusCounts = (clone $memberBase)
            ->select('family_members.status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('family_members.status')
            ->pluck('aggregate', 'family_members.status');

        $totalPeople = (clone $memberBase)->count();
        $activePeople = (int) ($memberStatusCounts['active'] ?? 0);

        $newFamilies = (clone $familyBase)
            ->whereBetween('families.created_at', [$filters['from'].' 00:00:00', $filters['to'].' 23:59:59'])
            ->count();

        $size = $this->familySize($tenantId, $filters['bcc_id'], $filters['status'], $totalFamilies, $totalPeople);
        $demographics = $this->ageDemographicsService->forFamilyDashboard($tenantId, $filters['bcc_id'], $filters['status']);
        $household = $this->household($tenantId, $filters['bcc_id'], $filters['status']);
        $attention = $this->attention($tenantId, $filters['bcc_id'], $filters['status'], $demographics);
        $profileIncomplete = $this->profileIncompleteCount($tenantId, $filters['bcc_id'], $filters['status']);
        $dataQuality = $this->dataQuality($totalFamilies, $attention, $totalPeople, $profileIncomplete);
        $growth = $this->growth($tenantId, $filters);
        $bcc = $this->bccRows($tenantId, $filters['status']);
        $locations = $this->locations($tenantId, $filters['bcc_id'], $filters['status'], $totalFamilies);
        $background = $this->background($memberBase, $totalPeople);

        $payload = [
            'filters' => $filters,
            'definitions' => $this->definitions($filters['period']),
            'population' => [
                'total_families' => $totalFamilies,
                'active_families' => $activeFamilies,
                'inactive_families' => $inactiveFamilies,
                'migrated_families' => $migratedFamilies,
                'total_people' => $totalPeople,
                'active_people' => $activePeople,
                'inactive_people' => (int) ($memberStatusCounts['inactive'] ?? 0),
                'deceased_people' => (int) ($memberStatusCounts['deceased'] ?? 0),
                'migrated_people' => (int) ($memberStatusCounts['migrated'] ?? 0),
                'families_with_members' => $size['families_with_members'],
                'families_without_members' => $size['families_without_members'],
            ],
            'kpis' => [
                'total_families' => $totalFamilies,
                'total_people' => $totalPeople,
                'active_families' => $activeFamilies,
                'active_people' => $activePeople,
                'new_families' => $newFamilies,
                'average_family_size' => $size['average'],
                'active_families_percent' => $totalFamilies > 0 ? round(($activeFamilies / $totalFamilies) * 100, 1) : 0.0,
                'active_people_percent' => $totalPeople > 0 ? round(($activePeople / $totalPeople) * 100, 1) : 0.0,
            ],
            'attention' => $attention,
            'demographics' => [
                'age_groups' => $demographics['age_groups'],
                'gender' => $demographics['gender'],
                'with_dob' => $demographics['with_dob'],
                'without_dob' => $demographics['without_dob'],
                'with_gender' => $demographics['with_gender'],
                'without_gender' => $demographics['without_gender'],
                'under_18' => $demographics['under_18'],
                'under_18_insight' => $demographics['with_dob'] > 0
                    ? $demographics['under_18'].' members are under 18.'
                    : null,
            ],
            'household' => array_merge($size, $household),
            'growth' => $growth,
            'bcc' => $bcc,
            'locations' => $locations,
            'background' => $background,
            'data_quality' => $dataQuality,
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'cached' => false,
            ],
        ];

        if ($includeContributions) {
            $payload['contributions'] = $this->contributions($tenantId, $filters);
        }

        if ($includePastoral) {
            $payload['pastoral'] = $this->pastoral($tenantId, $filters['bcc_id']);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{bcc_id: ?string, status: ?string, period: string, from: string, to: string}
     */
    public function resolveFilters(array $input): array
    {
        $period = (string) ($input['period'] ?? '12m');
        if (! in_array($period, ['30d', '3m', '6m', '12m', 'custom'], true)) {
            $period = '12m';
        }

        $to = Carbon::now()->toDateString();
        $from = match ($period) {
            '30d' => Carbon::now()->subDays(29)->toDateString(),
            '3m' => Carbon::now()->subMonthsNoOverflow(3)->toDateString(),
            '6m' => Carbon::now()->subMonthsNoOverflow(6)->toDateString(),
            'custom' => is_string($input['from'] ?? null) && $input['from'] !== ''
                ? $input['from']
                : Carbon::now()->subYear()->toDateString(),
            default => Carbon::now()->subYear()->addDay()->toDateString(),
        };

        if ($period === 'custom' && is_string($input['to'] ?? null) && $input['to'] !== '') {
            $to = $input['to'];
        }

        if (Carbon::parse($from)->diffInMonths(Carbon::parse($to), false) > 24) {
            $from = Carbon::parse($to)->subMonthsNoOverflow(24)->toDateString();
        }

        $status = $input['status'] ?? null;
        if (! in_array($status, ['active', 'inactive', 'migrated', null, ''], true)) {
            $status = null;
        }
        $status = $status === '' ? null : $status;

        $bccId = $input['bcc_id'] ?? null;
        $bccId = $bccId === '' ? null : $bccId;

        return [
            'bcc_id' => $bccId,
            'status' => $status,
            'period' => $period,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Family>
     */
    private function familyBase(int $tenantId, ?string $bccId, ?string $status)
    {
        $query = Family::query()
            ->where('families.tenant_id', $tenantId)
            ->whereNull('families.deleted_at');
        FamilyQueryFilters::applyBcc($query, $bccId, 'families.bcc_id');
        FamilyQueryFilters::applyFamilyStatus($query, $status, 'families.status');

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function familySize(int $tenantId, ?string $bccId, ?string $status, int $totalFamilies, int $totalPeople): array
    {
        $counts = DB::query()
            ->fromSub(function ($sub) use ($tenantId, $bccId, $status): void {
                $sub->from('family_members')
                    ->select('family_members.family_id', DB::raw('COUNT(*) as member_count'))
                    ->where('family_members.tenant_id', (string) $tenantId)
                    ->whereNull('family_members.deleted_at')
                    ->whereIn('family_members.family_id', function ($families) use ($tenantId, $bccId, $status): void {
                        $families->select('id')
                            ->from('families')
                            ->where('tenant_id', $tenantId)
                            ->whereNull('deleted_at');
                        FamilyQueryFilters::applyBcc($families, $bccId);
                        FamilyQueryFilters::applyFamilyStatus($families, $status);
                    })
                    ->groupBy('family_members.family_id');
            }, 'family_sizes')
            ->select([
                DB::raw("CASE
                    WHEN member_count = 1 THEN '1'
                    WHEN member_count = 2 THEN '2'
                    WHEN member_count BETWEEN 3 AND 4 THEN '3_4'
                    WHEN member_count BETWEEN 5 AND 6 THEN '5_6'
                    ELSE '7_plus'
                END as size_band"),
                DB::raw('COUNT(*) as families'),
            ])
            ->groupBy('size_band')
            ->pluck('families', 'size_band');

        $withMembers = (int) array_sum($counts->all());
        $withoutMembers = max(0, $totalFamilies - $withMembers);
        $average = $withMembers > 0 ? round($totalPeople / $withMembers, 2) : 0.0;

        $bands = [];
        foreach (['1', '2', '3_4', '5_6', '7_plus'] as $key) {
            $count = (int) ($counts[$key] ?? 0);
            $bands[$key] = [
                'count' => $count,
                'percent' => $withMembers > 0 ? round(($count / $withMembers) * 100, 1) : 0.0,
            ];
        }

        return [
            'bands' => $bands,
            'average' => $average,
            'families_with_members' => $withMembers,
            'families_without_members' => $withoutMembers,
        ];
    }

    /**
     * @return array{have_under_18: int, have_seniors: int, multiple_adults: int, no_active_head: int, head_gender: array<string, int>}
     */
    private function household(int $tenantId, ?string $bccId, ?string $status): array
    {
        $ageYears = BccAgeBands::ageYearsSql();
        $familyIds = function ($query) use ($tenantId, $bccId, $status): void {
            $query->select('id')
                ->from('families')
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at');
            FamilyQueryFilters::applyBcc($query, $bccId);
            FamilyQueryFilters::applyFamilyStatus($query, $status);
        };

        $haveUnder18 = (int) DB::table('families')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->tap(fn ($q) => FamilyQueryFilters::applyBcc($q, $bccId))
            ->tap(fn ($q) => FamilyQueryFilters::applyFamilyStatus($q, $status))
            ->whereExists(function ($sub) use ($tenantId, $ageYears): void {
                $sub->selectRaw('1')
                    ->from('family_members')
                    ->whereColumn('family_members.family_id', 'families.id')
                    ->where('family_members.tenant_id', (string) $tenantId)
                    ->whereNull('family_members.deleted_at')
                    ->whereNotNull('family_members.date_of_birth')
                    ->whereRaw("{$ageYears} < 18");
            })
            ->count();

        $haveSeniors = (int) DB::table('families')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->tap(fn ($q) => FamilyQueryFilters::applyBcc($q, $bccId))
            ->tap(fn ($q) => FamilyQueryFilters::applyFamilyStatus($q, $status))
            ->whereExists(function ($sub) use ($tenantId, $ageYears): void {
                $sub->selectRaw('1')
                    ->from('family_members')
                    ->whereColumn('family_members.family_id', 'families.id')
                    ->where('family_members.tenant_id', (string) $tenantId)
                    ->whereNull('family_members.deleted_at')
                    ->whereNotNull('family_members.date_of_birth')
                    ->whereRaw("{$ageYears} >= 60");
            })
            ->count();

        $multipleAdults = (int) DB::query()
            ->fromSub(function ($sub) use ($tenantId, $familyIds, $ageYears): void {
                $sub->from('family_members')
                    ->select('family_id')
                    ->where('tenant_id', (string) $tenantId)
                    ->whereNull('deleted_at')
                    ->whereNotNull('date_of_birth')
                    ->whereRaw("{$ageYears} >= 18")
                    ->whereIn('family_id', $familyIds)
                    ->groupBy('family_id')
                    ->havingRaw('COUNT(*) >= 2');
            }, 'multi_adult')
            ->count();

        $noActiveHead = (int) DB::table('families')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->tap(fn ($q) => FamilyQueryFilters::applyBcc($q, $bccId))
            ->tap(fn ($q) => FamilyQueryFilters::applyFamilyStatus($q, $status))
            ->whereNotExists(function ($sub) use ($tenantId): void {
                $sub->selectRaw('1')
                    ->from('family_members')
                    ->whereColumn('family_members.family_id', 'families.id')
                    ->where('family_members.tenant_id', (string) $tenantId)
                    ->whereNull('family_members.deleted_at')
                    ->where('family_members.status', 'active')
                    ->whereIn('family_members.relationship_to_head', ['self', 'head']);
            })
            ->count();

        $headGender = DB::table('family_members')
            ->selectRaw("COALESCE(NULLIF(LOWER(TRIM(gender)), ''), 'unknown') as gender_key")
            ->selectRaw('COUNT(*) as aggregate')
            ->where('tenant_id', (string) $tenantId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->whereIn('relationship_to_head', ['self', 'head'])
            ->whereIn('family_id', $familyIds)
            ->groupByRaw("COALESCE(NULLIF(LOWER(TRIM(gender)), ''), 'unknown')")
            ->pluck('aggregate', 'gender_key');

        return [
            'have_under_18' => $haveUnder18,
            'have_seniors' => $haveSeniors,
            'multiple_adults' => $multipleAdults,
            'no_active_head' => $noActiveHead,
            'head_gender' => [
                'male' => (int) ($headGender['male'] ?? 0),
                'female' => (int) ($headGender['female'] ?? 0),
                'other' => (int) ($headGender['other'] ?? 0),
                'unknown' => (int) ($headGender['unknown'] ?? 0),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $demographics
     * @return array<string, int>
     */
    private function attention(int $tenantId, ?string $bccId, ?string $status, array $demographics): array
    {
        $base = fn () => DB::table('families')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->tap(fn ($q) => FamilyQueryFilters::applyBcc($q, $bccId))
            ->tap(fn ($q) => FamilyQueryFilters::applyFamilyStatus($q, $status));

        $missingContact = (int) (clone $base())
            ->whereNotExists(function ($sub) use ($tenantId): void {
                $sub->selectRaw('1')
                    ->from('family_members')
                    ->whereColumn('family_members.family_id', 'families.id')
                    ->where('family_members.tenant_id', (string) $tenantId)
                    ->whereNull('family_members.deleted_at')
                    ->whereNotNull('family_members.phone')
                    ->whereRaw("TRIM(family_members.phone) <> ''");
            })
            ->count();

        $missingAddress = (int) (clone $base())
            ->where(function ($inner): void {
                $inner->whereNull('address_line_1')
                    ->orWhereRaw("TRIM(address_line_1) = ''");
            })
            ->count();

        $noMembers = (int) (clone $base())
            ->whereNotExists(function ($sub) use ($tenantId): void {
                $sub->selectRaw('1')
                    ->from('family_members')
                    ->whereColumn('family_members.family_id', 'families.id')
                    ->where('family_members.tenant_id', (string) $tenantId)
                    ->whereNull('family_members.deleted_at');
            })
            ->count();

        $noHead = (int) (clone $base())
            ->whereNotExists(function ($sub) use ($tenantId): void {
                $sub->selectRaw('1')
                    ->from('family_members')
                    ->whereColumn('family_members.family_id', 'families.id')
                    ->where('family_members.tenant_id', (string) $tenantId)
                    ->whereNull('family_members.deleted_at')
                    ->where('family_members.status', 'active')
                    ->whereIn('family_members.relationship_to_head', ['self', 'head']);
            })
            ->count();

        $missingRelationship = (int) $this->ageDemographicsService
            ->memberBaseQuery($tenantId, $bccId, $status)
            ->whereNull('family_members.relationship_to_head')
            ->count();

        return [
            'no_active_head' => $noHead,
            'no_contact' => $missingContact,
            'no_address' => $missingAddress,
            'no_members' => $noMembers,
            'missing_dob' => (int) $demographics['without_dob'],
            'missing_gender' => (int) $demographics['without_gender'],
            'missing_relationship' => $missingRelationship,
        ];
    }

    /**
     * @param  array<string, int>  $attention
     * @return array<string, mixed>
     */
    private function dataQuality(int $totalFamilies, array $attention, int $totalPeople, int $profileIncomplete): array
    {
        $complete = max(0, $totalFamilies - $profileIncomplete);

        return [
            'family_profile' => [
                'complete_count' => $complete,
                'incomplete_count' => $profileIncomplete,
                'percent' => $totalFamilies > 0 ? round(($complete / $totalFamilies) * 100, 1) : 0.0,
                'definition' => 'Families with an active head, address, and member contact number.',
            ],
            'members' => [
                'missing_dob' => $attention['missing_dob'],
                'missing_gender' => $attention['missing_gender'],
                'missing_relationship' => $attention['missing_relationship'],
                'total' => $totalPeople,
            ],
        ];
    }

    private function profileIncompleteCount(int $tenantId, ?string $bccId, ?string $status): int
    {
        return (int) DB::table('families')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->tap(fn ($q) => FamilyQueryFilters::applyBcc($q, $bccId))
            ->tap(fn ($q) => FamilyQueryFilters::applyFamilyStatus($q, $status))
            ->where(function ($outer) use ($tenantId): void {
                $outer->whereNotExists(function ($sub) use ($tenantId): void {
                    $sub->selectRaw('1')
                        ->from('family_members')
                        ->whereColumn('family_members.family_id', 'families.id')
                        ->where('family_members.tenant_id', (string) $tenantId)
                        ->whereNull('family_members.deleted_at')
                        ->where('family_members.status', 'active')
                        ->whereIn('family_members.relationship_to_head', ['self', 'head']);
                })->orWhere(function ($inner): void {
                    $inner->whereNull('address_line_1')
                        ->orWhereRaw("TRIM(address_line_1) = ''");
                })->orWhereNotExists(function ($sub) use ($tenantId): void {
                    $sub->selectRaw('1')
                        ->from('family_members')
                        ->whereColumn('family_members.family_id', 'families.id')
                        ->where('family_members.tenant_id', (string) $tenantId)
                        ->whereNull('family_members.deleted_at')
                        ->whereNotNull('family_members.phone')
                        ->whereRaw("TRIM(family_members.phone) <> ''");
                });
            })
            ->count();
    }

    /**
     * @param  array{bcc_id: ?string, status: ?string, period: string, from: string, to: string}  $filters
     * @return array<string, mixed>
     */
    private function growth(int $tenantId, array $filters): array
    {
        $from = $filters['from'];
        $to = $filters['to'];
        $weekly = $filters['period'] === '30d';
        $familySeries = $this->createdSeries('families', $tenantId, $from, $to, $weekly, $filters['bcc_id'], $filters['status']);
        $memberSeries = $this->memberCreatedSeries($tenantId, $from, $to, $weekly, $filters['bcc_id'], $filters['status']);

        $recent = $this->familyBase($tenantId, $filters['bcc_id'], $filters['status'])
            ->orderByDesc('families.created_at')
            ->limit(5)
            ->get(['id', 'family_name', 'family_code', 'created_at'])
            ->map(fn (Family $family) => [
                'id' => $family->id,
                'family_name' => $family->family_name,
                'family_code' => $family->family_code,
                'created_at' => $family->created_at?->toIso8601String(),
            ])
            ->all();

        return [
            'families' => $familySeries,
            'members' => $memberSeries,
            'recent_families' => $recent,
            'caption' => 'Newly created records',
        ];
    }

    /**
     * @return list<array{period: string, count: int}>
     */
    private function createdSeries(string $table, int $tenantId, string $from, string $to, bool $weekly, ?string $bccId, ?string $status): array
    {
        $trunc = $weekly
            ? (DB::connection()->getDriverName() === 'pgsql'
                ? "TO_CHAR(DATE_TRUNC('week', created_at), 'YYYY-MM-DD')"
                : "strftime('%Y-%W', created_at)")
            : (DB::connection()->getDriverName() === 'pgsql'
                ? "TO_CHAR(DATE_TRUNC('month', created_at), 'YYYY-MM')"
                : "strftime('%Y-%m', created_at)");

        $query = DB::table($table)
            ->selectRaw("{$trunc} as bucket")
            ->selectRaw('COUNT(*) as aggregate')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
        FamilyQueryFilters::applyBcc($query, $bccId);
        FamilyQueryFilters::applyFamilyStatus($query, $status);
        $rows = $query->groupBy('bucket')->orderBy('bucket')->pluck('aggregate', 'bucket');

        return $rows->map(fn ($count, $period) => [
            'period' => (string) $period,
            'count' => (int) $count,
        ])->values()->all();
    }

    /**
     * @return list<array{period: string, count: int}>
     */
    private function memberCreatedSeries(int $tenantId, string $from, string $to, bool $weekly, ?string $bccId, ?string $status): array
    {
        $trunc = $weekly
            ? (DB::connection()->getDriverName() === 'pgsql'
                ? "TO_CHAR(DATE_TRUNC('week', family_members.created_at), 'YYYY-MM-DD')"
                : "strftime('%Y-%W', family_members.created_at)")
            : (DB::connection()->getDriverName() === 'pgsql'
                ? "TO_CHAR(DATE_TRUNC('month', family_members.created_at), 'YYYY-MM')"
                : "strftime('%Y-%m', family_members.created_at)");

        $query = DB::table('family_members')
            ->selectRaw("{$trunc} as bucket")
            ->selectRaw('COUNT(*) as aggregate')
            ->where('family_members.tenant_id', (string) $tenantId)
            ->whereNull('family_members.deleted_at')
            ->whereBetween('family_members.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->whereIn('family_members.family_id', function ($families) use ($tenantId, $bccId, $status): void {
                $families->select('id')
                    ->from('families')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at');
                FamilyQueryFilters::applyBcc($families, $bccId);
                FamilyQueryFilters::applyFamilyStatus($families, $status);
            });
        $rows = $query->groupBy('bucket')->orderBy('bucket')->pluck('aggregate', 'bucket');

        return $rows->map(fn ($count, $period) => [
            'period' => (string) $period,
            'count' => (int) $count,
        ])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bccRows(int $tenantId, ?string $status): array
    {
        $familyQuery = Family::query()
            ->where('families.tenant_id', $tenantId)
            ->whereNull('families.deleted_at');
        FamilyQueryFilters::applyFamilyStatus($familyQuery, $status, 'families.status');

        $rows = (clone $familyQuery)
            ->leftJoin('bccs', 'bccs.id', '=', 'families.bcc_id')
            ->selectRaw("COALESCE(families.bcc_id::text, 'unassigned') as bcc_key")
            ->selectRaw("COALESCE(bccs.name, 'Unassigned') as bcc_name")
            ->selectRaw('COUNT(families.id) as families')
            ->selectRaw("SUM(CASE WHEN families.status = 'active' THEN 1 ELSE 0 END) as active_families")
            ->groupByRaw("COALESCE(families.bcc_id::text, 'unassigned'), COALESCE(bccs.name, 'Unassigned')");

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $rows = (clone $familyQuery)
                ->leftJoin('bccs', 'bccs.id', '=', 'families.bcc_id')
                ->selectRaw("COALESCE(families.bcc_id, 'unassigned') as bcc_key")
                ->selectRaw("COALESCE(bccs.name, 'Unassigned') as bcc_name")
                ->selectRaw('COUNT(families.id) as families')
                ->selectRaw("SUM(CASE WHEN families.status = 'active' THEN 1 ELSE 0 END) as active_families")
                ->groupByRaw("COALESCE(families.bcc_id, 'unassigned'), COALESCE(bccs.name, 'Unassigned')");
        }

        $familyRows = $rows->get();

        $peopleByBcc = DB::table('family_members')
            ->join('families', 'families.id', '=', 'family_members.family_id')
            ->where('family_members.tenant_id', (string) $tenantId)
            ->whereNull('family_members.deleted_at')
            ->whereNull('families.deleted_at')
            ->where('families.tenant_id', $tenantId)
            ->tap(fn ($q) => FamilyQueryFilters::applyFamilyStatus($q, $status, 'families.status'))
            ->selectRaw(DB::connection()->getDriverName() === 'pgsql'
                ? "COALESCE(families.bcc_id::text, 'unassigned') as bcc_key"
                : "COALESCE(families.bcc_id, 'unassigned') as bcc_key")
            ->selectRaw('COUNT(family_members.id) as people')
            ->groupBy('bcc_key')
            ->pluck('people', 'bcc_key');

        return $familyRows->map(function ($row) use ($peopleByBcc) {
            $key = (string) $row->bcc_key;
            $families = (int) $row->families;
            $people = (int) ($peopleByBcc[$key] ?? 0);

            return [
                'bcc_id' => $key === 'unassigned' ? 'unassigned' : $key,
                'name' => (string) $row->bcc_name,
                'families' => $families,
                'people' => $people,
                'average_family_size' => $families > 0 ? round($people / $families, 1) : 0.0,
                'active_families' => (int) $row->active_families,
            ];
        })->sortByDesc('families')->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function locations(int $tenantId, ?string $bccId, ?string $status, int $totalFamilies): array
    {
        $norm = FamilyQueryFilters::normalizeSql('families.city');
        $query = $this->familyBase($tenantId, $bccId, $status)
            ->selectRaw("CASE WHEN families.city IS NULL OR TRIM(families.city) = '' THEN 'not_recorded' ELSE {$norm} END as city_key")
            ->selectRaw("MIN(families.city) as city_label")
            ->selectRaw('COUNT(*) as families')
            ->groupBy('city_key')
            ->orderByDesc('families');

        $rows = $query->get();
        $top = $rows->filter(fn ($row) => $row->city_key !== 'not_recorded')->take(8);
        $notRecorded = $rows->firstWhere('city_key', 'not_recorded');
        $listed = $top->sum('families');
        $other = max(0, $totalFamilies - $listed - (int) ($notRecorded->families ?? 0));

        $out = $top->map(fn ($row) => [
            'city' => (string) ($row->city_label ?: $row->city_key),
            'city_key' => (string) $row->city_key,
            'families' => (int) $row->families,
            'percent' => $totalFamilies > 0 ? round(((int) $row->families / $totalFamilies) * 100, 1) : 0.0,
        ])->values()->all();

        if ($other > 0) {
            $out[] = [
                'city' => 'Other recorded',
                'city_key' => 'other',
                'families' => $other,
                'percent' => $totalFamilies > 0 ? round(($other / $totalFamilies) * 100, 1) : 0.0,
            ];
        }

        $out[] = [
            'city' => 'City not recorded',
            'city_key' => 'not_recorded',
            'families' => (int) ($notRecorded->families ?? 0),
            'percent' => $totalFamilies > 0 ? round(((int) ($notRecorded->families ?? 0) / $totalFamilies) * 100, 1) : 0.0,
        ];

        return $out;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Modules\Family\Models\FamilyMember>  $memberBase
     * @return array<string, mixed>
     */
    private function background($memberBase, int $totalPeople): array
    {
        return [
            'occupation' => $this->occupationDistribution($memberBase, $totalPeople),
            'education' => $this->educationDistribution($memberBase, $totalPeople),
        ];
    }

    /**
     * Qualification level only. Grouped in SQL, then classified in PHP on the distinct values.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Modules\Family\Models\FamilyMember>  $memberBase
     * @return array<string, mixed>
     */
    private function educationDistribution($memberBase, int $totalPeople): array
    {
        $column = 'family_members.education';
        $norm = FamilyQueryFilters::normalizeSql($column);
        $rows = (clone $memberBase)
            ->whereNotNull($column)
            ->whereRaw("TRIM({$column}) <> ''")
            ->selectRaw("{$norm} as value_key")
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('value_key')
            ->get();

        $blank = max(0, $totalPeople - (int) $rows->sum('aggregate'));
        $aggregated = MemberEducationQualification::aggregateGrouped($rows, $blank);

        return [
            'recorded' => $aggregated['recorded'],
            'not_recorded' => $aggregated['not_recorded'],
            'excluded' => $aggregated['excluded'],
            'total' => $totalPeople,
            'values' => $aggregated['values'],
            'definition' => 'Each member is in one consolidated education level. Institution names, streams, specializations, and trades are not categories. Percentages use all members in the current dashboard scope, including Not Recorded and Other / Unclassified.',
        ];
    }

    /**
     * One grouped query, then the shared classifier. Distinct titles are classified in PHP.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Modules\Family\Models\FamilyMember>  $memberBase
     * @return array<string, mixed>
     */
    private function occupationDistribution($memberBase, int $totalPeople): array
    {
        $column = 'family_members.occupation';
        $norm = FamilyQueryFilters::normalizeSql($column);
        $rows = (clone $memberBase)
            ->whereNotNull($column)
            ->whereRaw("TRIM({$column}) <> ''")
            ->selectRaw("{$norm} as value_key")
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('value_key')
            ->get();

        $blank = max(0, $totalPeople - (int) $rows->sum('aggregate'));

        return OccupationClassificationService::aggregateGrouped($rows, $blank);
    }

    /**
     * @param  array{bcc_id: ?string, status: ?string, period: string, from: string, to: string}  $filters
     * @return array<string, mixed>
     */
    private function contributions(int $tenantId, array $filters): array
    {
        $familyIds = function ($query) use ($tenantId, $filters): void {
            $query->select('id')
                ->from('families')
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at');
            FamilyQueryFilters::applyBcc($query, $filters['bcc_id']);
            FamilyQueryFilters::applyFamilyStatus($query, $filters['status']);
        };

        $withPaymentInPeriod = (int) DonationPayment::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereIn('family_id', $familyIds)
            ->whereBetween('payment_date', [$filters['from'], $filters['to']])
            ->distinct()
            ->count('family_id');

        $lifetimePaidFamilyIds = DonationPayment::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereIn('family_id', $familyIds)
            ->distinct()
            ->pluck('family_id');

        $scopedFamilyCount = (int) Family::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->tap(fn ($q) => FamilyQueryFilters::applyBcc($q, $filters['bcc_id']))
            ->tap(fn ($q) => FamilyQueryFilters::applyFamilyStatus($q, $filters['status']))
            ->count();

        $noPaymentLifetime = max(0, $scopedFamilyCount - $lifetimePaidFamilyIds->count());

        $dueOutstanding = ContributionDue::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereColumn('amount_due', '>', 'amount_paid')
            ->whereIn('family_id', $familyIds)
            ->distinct()
            ->pluck('family_id');

        $installmentOutstanding = ProjectInstallmentDue::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereColumn('amount_due', '>', 'amount_paid')
            ->whereIn('family_id', $familyIds)
            ->distinct()
            ->pluck('family_id');

        $periodCollected = (string) DonationPayment::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereIn('family_id', $familyIds)
            ->whereBetween('payment_date', [$filters['from'], $filters['to']])
            ->sum('amount');

        return [
            'families_with_payment_in_period' => $withPaymentInPeriod,
            'families_with_outstanding' => $dueOutstanding->merge($installmentOutstanding)->unique()->count(),
            'families_with_no_successful_payment_lifetime' => $noPaymentLifetime,
            'period_collected' => $periodCollected,
            'currency_code' => $this->currencyResolver->currencyCodeForTenantId($tenantId),
            'definitions' => [
                'families_with_payment_in_period' => 'Families with a successful payment in the selected period.',
                'families_with_outstanding' => 'Families with an outstanding contribution due or project installment now.',
                'families_with_no_successful_payment_lifetime' => 'Families with no successful payment recorded (lifetime).',
                'freshness' => 'Contribution figures may lag up to 60 seconds until Refresh.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pastoral(int $tenantId, ?string $bccId): array
    {
        try {
            $metrics = $this->sacramentQuery->progressionMetrics(
                $tenantId,
                $bccId === FamilyQueryFilters::UNASSIGNED_BCC ? null : $bccId
            );

            return [
                'error' => null,
                'baptized_without_communion' => $metrics[ParishProgressionFilter::BAPTIZED_WITHOUT_COMMUNION]['count'] ?? 0,
                'baptized_without_confirmation' => $metrics[ParishProgressionFilter::BAPTIZED_WITHOUT_CONFIRMATION]['count'] ?? 0,
            ];
        } catch (Throwable) {
            return [
                'error' => 'Sacramental follow-up could not be loaded.',
                'baptized_without_communion' => null,
                'baptized_without_confirmation' => null,
            ];
        }
    }

    /**
     * @return array<string, string>
     */
    private function definitions(string $period): array
    {
        $periodLabel = match ($period) {
            '30d' => 'last 30 days',
            '3m' => 'last 3 months',
            '6m' => 'last 6 months',
            'custom' => 'the selected period',
            default => 'last 12 months',
        };

        return [
            'total_people' => 'All non-deleted family memberships in the current family scope, including inactive, deceased, and migrated members.',
            'active_people' => 'Memberships whose member status is active.',
            'new_families' => 'Family records created in '.$periodLabel.'. This is not the current total.',
            'period_scope' => 'The period filter changes only newly created counts and growth charts. Population totals follow BCC and family status.',
            'family_profile_completeness' => 'Share of families with an active head, address, and member contact number. Date of birth, gender, occupation, and education are not part of this score.',
            'age_percent' => 'Age percentages use members with a recorded date of birth.',
            'gender_percent' => 'Gender percentages use members with a recorded gender.',
        ];
    }
}
