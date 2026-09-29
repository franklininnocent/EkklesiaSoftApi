<?php

namespace Modules\Donations\Services;

use Carbon\Carbon;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;
use Modules\Tenants\Support\ChurchMoneyFormatter;

class CollectionForecastService
{
    public function __construct(
        private readonly ExecutiveReportMetricsService $metrics
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $tenantId, int $horizonMonths = 3): array
    {
        $history = $this->monthlyHistory($tenantId, 12);
        $recent = array_slice($history, -3);
        $recentValues = array_map(fn (array $row): float => (float) $row['collected'], $recent);
        $movingAverage = count($recentValues) > 0
            ? MoneyMath::round(array_sum($recentValues) / count($recentValues))
            : 0.0;

        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $today = DonationBusinessDate::today($tenantId);
        $currentMonthKey = Carbon::parse($today, $timezone)->format('Y-m');
        $currentMonthCollected = 0.0;
        foreach ($history as $row) {
            if ($row['period'] === $currentMonthKey) {
                $currentMonthCollected = (float) $row['collected'];
                break;
            }
        }

        $todayCarbon = Carbon::parse($today, $timezone);
        $dayOfMonth = max(1, (int) $todayCarbon->day);
        $daysInMonth = (int) $todayCarbon->daysInMonth;
        $dailyPace = $currentMonthCollected / $dayOfMonth;
        $endOfMonthProjection = MoneyMath::round($dailyPace * $daysInMonth);

        $growthPct = $this->growthPct($history);
        $projections = [];

        for ($i = 1; $i <= max(1, min($horizonMonths, 6)); $i++) {
            $month = $todayCarbon->copy()->addMonths($i);
            $seasonalFactor = 1 + ($growthPct / 100);
            $projected = MoneyMath::round($movingAverage * pow($seasonalFactor, $i));

            $projections[] = [
                'period' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'projected_collected' => $projected,
                'confidence' => max(35, min(90, 70 - ($i * 8))),
            ];
        }

        return [
            'method' => 'moving_average_with_pace',
            'history' => $history,
            'signals' => [
                'moving_average_3m' => $movingAverage,
                'current_month_collected' => MoneyMath::round($currentMonthCollected),
                'current_month_projection' => $endOfMonthProjection,
                'collection_growth_pct' => $growthPct,
                'daily_pace' => MoneyMath::round($dailyPace),
                'elapsed_days' => $dayOfMonth,
                'days_in_month' => $daysInMonth,
                'moving_average_source_periods' => array_map(
                    fn (array $row): string => (string) $row['period'],
                    $recent
                ),
            ],
            'projections' => $projections,
            'narrative' => sprintf(
                'Based on the last 3 months, collections are averaging %s. At the current daily pace, this month may reach about %s.',
                ChurchMoneyFormatter::formatForTenant($tenantId, $movingAverage),
                ChurchMoneyFormatter::formatForTenant($tenantId, $endOfMonthProjection)
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function monthlyHistory(int $tenantId, int $months): array
    {
        $buckets = $this->metrics->trendMonthBuckets($tenantId);
        if (count($buckets) > $months) {
            $buckets = array_slice($buckets, -$months);
        }

        $monthExpr = $this->metrics->sqlYearMonthExpression('payment_date');
        $firstStart = $buckets[0]['start'];
        $lastEnd = $buckets[count($buckets) - 1]['end'];

        $rows = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereBetween('payment_date', [$firstStart, $lastEnd])
            ->selectRaw("{$monthExpr} as period, COALESCE(SUM(amount), 0) as collected")
            ->groupByRaw($monthExpr)
            ->get();

        $collectedByMonth = [];
        foreach ($rows as $row) {
            $key = trim((string) $row->period);
            if ($key !== '') {
                $collectedByMonth[$key] = MoneyMath::toApiNumber($row->collected);
            }
        }

        $currentRange = $this->metrics->currentMonthCollectionRange($tenantId);
        $currentMtd = $this->metrics->sumSucceededPayments(
            $tenantId,
            $currentRange['start'],
            $currentRange['end']
        );

        $result = [];
        foreach ($buckets as $bucket) {
            $key = $bucket['period'];
            $collected = $bucket['is_current']
                ? $currentMtd
                : (float) ($collectedByMonth[$key] ?? 0);

            $result[] = [
                'period' => $key,
                'label' => $bucket['label'],
                'collected' => MoneyMath::round($collected),
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $history
     */
    private function growthPct(array $history): float
    {
        if (count($history) < 2) {
            return 0.0;
        }

        $previous = (float) $history[count($history) - 2]['collected'];
        $current = (float) $history[count($history) - 1]['collected'];

        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
