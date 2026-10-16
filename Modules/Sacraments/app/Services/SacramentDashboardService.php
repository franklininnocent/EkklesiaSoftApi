<?php

namespace Modules\Sacraments\Services;

use App\Support\UserFacingDate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\BCC\Models\BCC;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Services\Dashboard\SacramentDemographicsBuilder;
use Modules\Sacraments\Services\Dashboard\SacramentGapAnalysisBuilder;
use Modules\Sacraments\Services\Dashboard\SacramentMatrimonyBuilder;
use Modules\Sacraments\Support\SacramentStatus;
use Modules\Sacraments\Support\SacramentTypeCode;
use Modules\Tenants\Support\TenantCacheVersion;

class SacramentDashboardService
{
    private const CACHE_TTL_SECONDS = 300;

    private const CACHE_PREFIX = 'sacrament_dashboard';

    public function __construct(
        private readonly SacramentDemographicsBuilder $demographicsBuilder,
        private readonly SacramentMatrimonyBuilder $matrimonyBuilder,
        private readonly SacramentGapAnalysisBuilder $gapAnalysisBuilder,
    ) {}

    /**
     * @param  list<string>  $restrictedTypeCodes
     * @return array<string, mixed>
     */
    public function getSummary(int $tenantId, array $params, array $restrictedTypeCodes = []): array
    {
        $cacheKey = $this->buildCacheKey($tenantId, $params, $restrictedTypeCodes);
        $cacheHit = Cache::has($cacheKey);

        $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($tenantId, $params, $restrictedTypeCodes) {
            $startedAt = microtime(true);
            $summary = $this->buildSummary($tenantId, $params, $restrictedTypeCodes);
            $summary['meta']['duration_ms'] = round((microtime(true) - $startedAt) * 1000, 1);

            return $summary;
        });

        $payload['meta']['cached'] = $cacheHit;

        return $payload;
    }

    /**
     * Parish executive snapshot: calendar year-to-date register activity.
     * Aggregates in SQL (no row payload). Marriage is one register record, not two spouses.
     *
     * @param  list<string>  $restrictedTypeCodes
     * @return array<string, mixed>
     */
    public function getExecutiveSnapshot(int $tenantId, array $restrictedTypeCodes = []): array
    {
        $cacheKey = TenantCacheVersion::scopedKey(
            $tenantId,
            self::CACHE_PREFIX,
            'executive_ytd_mix:restricted_'.md5(implode(',', $restrictedTypeCodes))
        );

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($tenantId, $restrictedTypeCodes) {
            return $this->buildExecutiveSnapshot($tenantId, $restrictedTypeCodes);
        });
    }

    /**
     * @param  list<string>  $restrictedTypeCodes
     * @return array<string, mixed>
     */
    private function buildExecutiveSnapshot(int $tenantId, array $restrictedTypeCodes): array
    {
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $now = Carbon::now($timezone);
        $yearFrom = $now->copy()->startOfYear()->toDateString();
        $yearTo = $now->toDateString();
        $monthFrom = $now->copy()->startOfMonth()->toDateString();
        $monthTo = $now->copy()->endOfMonth()->toDateString();

        $base = $this->baseQuery($tenantId, $restrictedTypeCodes, null);

        $counts = (clone $base)->selectRaw(
            '
            SUM(CASE WHEN sacraments.date_administered BETWEEN ? AND ? THEN 1 ELSE 0 END) as this_year,
            SUM(CASE WHEN sacraments.date_administered BETWEEN ? AND ? THEN 1 ELSE 0 END) as this_month
        ',
            [$yearFrom, $yearTo, $monthFrom, $monthTo]
        )->first();

        $thisYear = (int) ($counts->this_year ?? 0);
        $thisMonth = (int) ($counts->this_month ?? 0);

        $byTypeRows = (clone $base)
            ->whereBetween('sacraments.date_administered', [$yearFrom, $yearTo])
            ->join('sacrament_types', 'sacrament_types.id', '=', 'sacraments.sacrament_type_id')
            ->selectRaw('sacrament_types.id as type_id, sacrament_types.code as type_code, sacrament_types.name as type_name, COUNT(sacraments.id) as aggregate')
            ->groupBy('sacrament_types.id', 'sacrament_types.code', 'sacrament_types.name')
            ->get();

        $mix = [];
        foreach ($byTypeRows as $row) {
            $code = SacramentTypeCode::normalize((string) $row->type_code) ?? strtoupper((string) $row->type_code);
            if (SacramentTypeCode::isExcludedFromStandardDashboardCharts($code)) {
                continue;
            }

            $count = (int) $row->aggregate;
            if ($count <= 0) {
                continue;
            }

            $mix[] = [
                'code' => $code,
                'label' => (string) $row->type_name,
                'sacrament_type_id' => (int) $row->type_id,
                'count' => $count,
            ];
        }

        usort($mix, static function (array $a, array $b): int {
            $cmp = $b['count'] <=> $a['count'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) $a['code'], (string) $b['code']);
        });

        $mixTotal = array_sum(array_column($mix, 'count'));
        foreach ($mix as &$slice) {
            $slice['percent'] = $mixTotal > 0
                ? round(($slice['count'] / $mixTotal) * 100, 1)
                : 0.0;
        }
        unset($slice);

        $emptyReason = 'none';
        if ($mixTotal === 0) {
            $emptyReason = $thisYear > 0 ? 'only_excluded_types' : 'no_register_activity';
        }

        return [
            'total_period' => $thisYear,
            'this_year' => $thisYear,
            'this_month' => $thisMonth,
            'period_label' => 'Year to date',
            'date_from' => $yearFrom,
            'date_to' => $yearTo,
            'month_from' => $monthFrom,
            'month_to' => $monthTo,
            'activity_mix' => $mix,
            'activity_mix_total' => $mixTotal,
            'activity_mix_empty_reason' => $emptyReason,
            'drilldown' => 'sacraments.home',
        ];
    }

    /**
     * @param  list<string>  $restrictedTypeCodes
     * @return array<string, mixed>
     */
    private function buildSummary(int $tenantId, array $params, array $restrictedTypeCodes = []): array
    {
        $bccId = isset($params['bcc_id']) && $params['bcc_id'] !== ''
            ? (string) $params['bcc_id']
            : null;

        if ($bccId !== null) {
            $bccExists = BCC::query()
                ->where('id', $bccId)
                ->where('tenant_id', $tenantId)
                ->exists();

            if (! $bccExists) {
                throw new \InvalidArgumentException('BCC not found for this parish.');
            }
        }

        if (filter_var($params['gaps_only'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            if (! filter_var($params['include_gaps'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw new \InvalidArgumentException('gaps_only requires include_gaps.');
            }

            $types = $this->loadActiveTypes($restrictedTypeCodes);
            $includeMarriageGaps = filter_var($params['include_marriage_gaps'] ?? false, FILTER_VALIDATE_BOOLEAN);

            return [
                'gaps' => $this->gapAnalysisBuilder->build(
                    $tenantId,
                    $types,
                    $restrictedTypeCodes,
                    $bccId,
                    $includeMarriageGaps
                ),
                'meta' => [
                    'restricted_types_excluded' => array_values(array_map(
                        static fn (string $code) => SacramentTypeCode::normalize($code) ?? strtoupper($code),
                        $restrictedTypeCodes
                    )),
                    'generated_at' => now()->toIso8601String(),
                    'gaps_only' => true,
                ],
            ];
        }

        $minimal = filter_var($params['minimal'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $preset = (string) ($params['preset'] ?? '');
        if ($preset === 'calendar_month_mtd') {
            $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
            $params['date_from'] = Carbon::now($timezone)->startOfMonth()->toDateString();
            $params['date_to'] = Carbon::now($timezone)->toDateString();
        }

        $hasExplicitFrom = isset($params['date_from']) && $params['date_from'] !== '';
        $hasExplicitTo = isset($params['date_to']) && $params['date_to'] !== '';
        $isAllTime = ! $hasExplicitFrom && ! $hasExplicitTo;

        $allTimeQuery = $this->baseQuery($tenantId, $restrictedTypeCodes, $bccId);

        if ($isAllTime) {
            $earliestDate = (clone $allTimeQuery)->min('date_administered');
            $from = $earliestDate !== null
                ? Carbon::parse($earliestDate)->startOfDay()
                : now()->startOfYear()->startOfDay();
            $to = now()->endOfDay();
            $periodQuery = clone $allTimeQuery;
            $periodLabel = 'All time';
        } else {
            $dateFrom = (string) ($params['date_from'] ?? now()->startOfYear()->toDateString());
            $dateTo = (string) ($params['date_to'] ?? now()->toDateString());
            $from = Carbon::parse($dateFrom)->startOfDay();
            $to = Carbon::parse($dateTo)->endOfDay();

            if ($from->gt($to)) {
                throw new \InvalidArgumentException('date_from must be on or before date_to.');
            }

            $periodQuery = (clone $allTimeQuery)
                ->whereBetween('date_administered', [$from->toDateString(), $to->toDateString()]);
            $periodLabel = $this->periodLabel($from, $to);
        }

        $types = $this->loadActiveTypes($restrictedTypeCodes);
        $typeIds = $types->pluck('id')->all();

        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $monthStart = Carbon::now($timezone)->startOfMonth()->toDateString();
        $monthEnd = Carbon::now($timezone)->endOfMonth()->toDateString();

        $aggregates = $this->buildPeriodAggregates($allTimeQuery, $from, $to, $monthStart, $monthEnd);
        $totalPeriod = $aggregates['total_period'];
        $totalAllTime = $aggregates['total_all_time'];
        $thisMonth = $aggregates['this_month'];

        $monthsInPeriod = max(1, $from->diffInMonths($to->copy()->endOfMonth()) + 1);
        $monthlyAverage = round($totalPeriod / $monthsInPeriod, 1);

        $byType = $this->buildByTypeCounts($types, $periodQuery, $allTimeQuery, $from, $to);
        $byTypeTotals = array_map(static fn (array $row) => [
            'code' => $row['code'],
            'label' => $row['label'],
            'count' => $row['period'],
        ], $byType);

        if ($minimal) {
            return [
                'period' => [
                    'date_from' => $from->toDateString(),
                    'date_to' => $to->toDateString(),
                    'label' => $periodLabel,
                ],
                'kpis' => [
                    'total_period' => $totalPeriod,
                    'this_month' => $thisMonth,
                ],
                'breakdowns' => [
                    'by_type_totals' => $byTypeTotals,
                ],
                'recent' => [],
                'meta' => [
                    'restricted_types_excluded' => array_values(array_map(
                        static fn (string $code) => SacramentTypeCode::normalize($code) ?? strtoupper($code),
                        $restrictedTypeCodes
                    )),
                    'generated_at' => now()->toIso8601String(),
                    'minimal' => true,
                ],
            ];
        }

        $yoyGrowthPct = $this->growthPct($aggregates['yoy_current'], $aggregates['yoy_prior']);
        $matrimonyTypeId = $this->matrimonyBuilder->resolveMatrimonyTypeId($types);
        $includeGaps = filter_var($params['include_gaps'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $includeMarriageGaps = filter_var($params['include_marriage_gaps'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $payload = [
            'period' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'label' => $periodLabel,
            ],
            'kpis' => [
                'total_period' => $totalPeriod,
                'total_all_time' => $totalAllTime,
                'this_month' => $thisMonth,
                'monthly_average' => $monthlyAverage,
                'yoy_growth_pct' => $yoyGrowthPct,
                'by_type' => $byType,
            ],
            'trends' => $this->buildTrends($tenantId, $restrictedTypeCodes, $bccId, $to, $typeIds),
            'breakdowns' => [
                'member_status' => [
                    'member' => $aggregates['member'],
                    'non_member' => $aggregates['non_member'],
                    'unknown' => 0,
                ],
                'by_type_totals' => $byTypeTotals,
            ],
            'demographics' => $this->demographicsBuilder->build($periodQuery),
            'matrimony' => $this->matrimonyBuilder->build($periodQuery, $allTimeQuery, $matrimonyTypeId),
            'recent' => $this->buildRecentRecords($periodQuery),
            'meta' => [
                'restricted_types_excluded' => array_values(array_map(
                    static fn (string $code) => SacramentTypeCode::normalize($code) ?? strtoupper($code),
                    $restrictedTypeCodes
                )),
                'generated_at' => now()->toIso8601String(),
            ],
        ];

        if ($includeGaps) {
            $payload['gaps'] = $this->gapAnalysisBuilder->build(
                $tenantId,
                $types,
                $restrictedTypeCodes,
                $bccId,
                $includeMarriageGaps
            );
        }

        return $payload;
    }

    /**
     * @param  list<string>  $restrictedTypeCodes
     */
    private function baseQuery(int $tenantId, array $restrictedTypeCodes, ?string $bccId): Builder
    {
        $query = Sacrament::query()
            ->forTenant($tenantId)
            ->whereIn('status', [SacramentStatus::REGISTERED, SacramentStatus::CONDITIONAL]);

        if ($bccId !== null) {
            $query->byBCC($bccId);
        }

        $this->applyRestrictedTypeExclusion($query, $restrictedTypeCodes);

        return $query;
    }

    /**
     * @param  list<string>  $restrictedTypeCodes
     */
    private function applyRestrictedTypeExclusion(Builder $query, array $restrictedTypeCodes): void
    {
        if ($restrictedTypeCodes === []) {
            return;
        }

        $codes = [];
        foreach ($restrictedTypeCodes as $code) {
            $normalized = SacramentTypeCode::normalize((string) $code) ?? strtoupper((string) $code);
            $codes[] = $normalized;
            if ($normalized === SacramentTypeCode::RECONCILIATION) {
                $codes = array_merge($codes, ['RECONCILIATION', 'CONFESSION', 'PENANCE']);
            }
        }

        $codes = array_values(array_unique(array_map('strtoupper', $codes)));

        $query->whereHas('sacramentType', function ($typeQuery) use ($codes) {
            $typeQuery->whereRaw(
                'UPPER(code) NOT IN ('.implode(',', array_fill(0, count($codes), '?')).')',
                $codes
            );
        });
    }

    /**
     * @param  list<string>  $restrictedTypeCodes
     * @return Collection<int, SacramentType>
     */
    private function loadActiveTypes(array $restrictedTypeCodes)
    {
        $query = SacramentType::query()->active()->ordered();

        if ($restrictedTypeCodes !== []) {
            $codes = [];
            foreach ($restrictedTypeCodes as $code) {
                $normalized = SacramentTypeCode::normalize((string) $code) ?? strtoupper((string) $code);
                $codes[] = $normalized;
            }
            $codes = array_values(array_unique(array_map('strtoupper', $codes)));

            $query->whereRaw(
                'UPPER(code) NOT IN ('.implode(',', array_fill(0, count($codes), '?')).')',
                $codes
            );
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, SacramentType>  $types
     * @return list<array<string, mixed>>
     */
    private function buildByTypeCounts($types, Builder $periodQuery, Builder $allTimeQuery, Carbon $from, Carbon $to): array
    {
        $periodCounts = (clone $periodQuery)
            ->selectRaw('sacrament_type_id, COUNT(*) as aggregate')
            ->groupBy('sacrament_type_id')
            ->pluck('aggregate', 'sacrament_type_id');

        $allTimeCounts = (clone $allTimeQuery)
            ->selectRaw('sacrament_type_id, COUNT(*) as aggregate')
            ->groupBy('sacrament_type_id')
            ->pluck('aggregate', 'sacrament_type_id');

        $priorFrom = $from->copy()->subYear();
        $priorTo = $to->copy()->subYear();

        $priorCounts = (clone $allTimeQuery)
            ->whereBetween('date_administered', [$priorFrom->toDateString(), $priorTo->toDateString()])
            ->selectRaw('sacrament_type_id, COUNT(*) as aggregate')
            ->groupBy('sacrament_type_id')
            ->pluck('aggregate', 'sacrament_type_id');

        $rows = [];
        foreach ($types as $type) {
            $period = (int) ($periodCounts[$type->id] ?? 0);
            $prior = (int) ($priorCounts[$type->id] ?? 0);
            $rows[] = [
                'sacrament_type_id' => (int) $type->id,
                'code' => SacramentTypeCode::normalize($type->code) ?? strtoupper((string) $type->code),
                'label' => (string) $type->name,
                'period' => $period,
                'all_time' => (int) ($allTimeCounts[$type->id] ?? 0),
                'yoy_pct' => $this->growthPct($period, $prior),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $typeIds
     * @param  list<string>  $restrictedTypeCodes
     * @return array<string, mixed>
     */
    private function buildTrends(int $tenantId, array $restrictedTypeCodes, ?string $bccId, Carbon $endDate, array $typeIds): array
    {
        $types = SacramentType::query()->whereIn('id', $typeIds)->ordered()->get();

        $trendEnd = $endDate->copy()->endOfMonth();
        $trendStart = $trendEnd->copy()->subMonths(11)->startOfMonth();
        $monthExpr = DB::connection()->getDriverName() === 'pgsql'
            ? "TO_CHAR(date_administered, 'YYYY-MM')"
            : "strftime('%Y-%m', date_administered)";

        $countsByTypeAndMonth = $this->baseQuery($tenantId, $restrictedTypeCodes, $bccId)
            ->whereIn('sacrament_type_id', $typeIds)
            ->whereBetween('date_administered', [$trendStart->toDateString(), $trendEnd->toDateString()])
            ->selectRaw("sacrament_type_id, {$monthExpr} as period, COUNT(*) as count")
            ->groupBy('sacrament_type_id', 'period')
            ->get()
            ->groupBy('sacrament_type_id')
            ->map(fn (Collection $rows) => $rows->pluck('count', 'period'));

        $series = [];
        foreach ($types as $type) {
            $code = SacramentTypeCode::normalize($type->code) ?? strtoupper((string) $type->code);
            if (SacramentTypeCode::isExcludedFromStandardDashboardCharts($code)) {
                continue;
            }

            $typeCounts = $countsByTypeAndMonth->get($type->id, collect());
            $points = [];
            for ($i = 0; $i < 12; $i++) {
                $month = $trendStart->copy()->addMonths($i);
                $period = $month->format('Y-m');
                $points[] = [
                    'period' => $period,
                    'label' => $month->format('M'),
                    'count' => (int) ($typeCounts[$period] ?? 0),
                ];
            }

            $series[] = [
                'code' => SacramentTypeCode::normalize($type->code) ?? strtoupper((string) $type->code),
                'label' => (string) $type->name,
                'points' => $points,
            ];
        }

        return [
            'granularity' => 'month',
            'start' => $trendStart->format('Y-m'),
            'end' => $trendEnd->format('Y-m'),
            'series' => $series,
        ];
    }

    /**
     * @return array{
     *     total_period: int,
     *     total_all_time: int,
     *     this_month: int,
     *     member: int,
     *     non_member: int,
     *     yoy_current: int,
     *     yoy_prior: int
     * }
     */
    private function buildPeriodAggregates(
        Builder $allTimeQuery,
        Carbon $from,
        Carbon $to,
        string $monthStart,
        string $monthEnd
    ): array {
        $periodFrom = $from->toDateString();
        $periodTo = $to->toDateString();
        $priorFrom = $from->copy()->subYear()->toDateString();
        $priorTo = $to->copy()->subYear()->toDateString();

        $row = (clone $allTimeQuery)->selectRaw(
            '
            COUNT(*) as total_all_time,
            SUM(CASE WHEN date_administered BETWEEN ? AND ? THEN 1 ELSE 0 END) as total_period,
            SUM(CASE WHEN date_administered BETWEEN ? AND ? THEN 1 ELSE 0 END) as this_month,
            SUM(CASE WHEN date_administered BETWEEN ? AND ? THEN 1 ELSE 0 END) as yoy_current,
            SUM(CASE WHEN date_administered BETWEEN ? AND ? THEN 1 ELSE 0 END) as yoy_prior,
            SUM(CASE WHEN date_administered BETWEEN ? AND ? AND family_id IS NOT NULL THEN 1 ELSE 0 END) as member_period,
            SUM(CASE WHEN date_administered BETWEEN ? AND ? AND family_id IS NULL THEN 1 ELSE 0 END) as non_member_period
        ',
            [
                $periodFrom, $periodTo,
                $monthStart, $monthEnd,
                $periodFrom, $periodTo,
                $priorFrom, $priorTo,
                $periodFrom, $periodTo,
                $periodFrom, $periodTo,
            ]
        )->first();

        return [
            'total_period' => (int) ($row->total_period ?? 0),
            'total_all_time' => (int) ($row->total_all_time ?? 0),
            'this_month' => (int) ($row->this_month ?? 0),
            'member' => (int) ($row->member_period ?? 0),
            'non_member' => (int) ($row->non_member_period ?? 0),
            'yoy_current' => (int) ($row->yoy_current ?? 0),
            'yoy_prior' => (int) ($row->yoy_prior ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRecentRecords(Builder $periodQuery): array
    {
        return (clone $periodQuery)
            ->with('sacramentType:id,name,code')
            ->orderByDesc('date_administered')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'recipient_name', 'date_administered', 'place_administered', 'sacrament_type_id', 'status'])
            ->map(static function (Sacrament $sacrament) {
                return [
                    'id' => (int) $sacrament->id,
                    'recipient_name' => (string) $sacrament->recipient_name,
                    'date_administered' => $sacrament->date_administered?->toDateString(),
                    'place_administered' => $sacrament->place_administered,
                    'status' => (string) $sacrament->status,
                    'type' => [
                        'id' => (int) $sacrament->sacrament_type_id,
                        'name' => $sacrament->sacramentType?->name,
                        'code' => $sacrament->sacramentType?->code,
                    ],
                ];
            })
            ->all();
    }

    private function growthPct(int $current, int $prior): float
    {
        if ($prior > 0) {
            return round((($current - $prior) / $prior) * 100, 1);
        }

        return $current > 0 ? 100.0 : 0.0;
    }

    private function periodLabel(Carbon $from, Carbon $to): string
    {
        if ($from->isSameDay(now()->startOfYear()) && $to->isSameDay(now()->endOfDay())) {
            return 'Year to date';
        }

        if ($from->isSameMonth($to) && $from->isSameYear($to) && $from->day === 1 && $to->isLastOfMonth()) {
            return UserFacingDate::formatMonthYear($from);
        }

        return UserFacingDate::formatDate($from).' – '.UserFacingDate::formatDate($to);
    }

    /**
     * @param  list<string>  $restrictedTypeCodes
     */
    private function buildCacheKey(int $tenantId, array $params, array $restrictedTypeCodes): string
    {
        $parts = [
            'from_'.($params['date_from'] ?? 'default'),
            'to_'.($params['date_to'] ?? 'default'),
            'preset_'.($params['preset'] ?? 'none'),
            'minimal_'.(filter_var($params['minimal'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0'),
            'bcc_'.($params['bcc_id'] ?? 'all'),
            'gaps_'.(filter_var($params['include_gaps'] ?? true, FILTER_VALIDATE_BOOLEAN) ? '1' : '0'),
            'gaps_only_'.(filter_var($params['gaps_only'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0'),
            'marriage_gaps_'.(filter_var($params['include_marriage_gaps'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0'),
            'progression_unmarried_cohorts',
            'standard_chart_exclusions',
            'restricted_'.md5(implode(',', $restrictedTypeCodes)),
        ];

        return TenantCacheVersion::scopedKey($tenantId, self::CACHE_PREFIX, implode(':', $parts));
    }
}
