<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Support\MoneyMath;

class CollectionForecastService
{
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

        $currentMonthKey = now()->format('Y-m');
        $currentMonthCollected = 0.0;
        foreach ($history as $row) {
            if ($row['period'] === $currentMonthKey) {
                $currentMonthCollected = (float) $row['collected'];
                break;
            }
        }

        $dayOfMonth = max(1, (int) now()->day);
        $daysInMonth = (int) now()->daysInMonth;
        $dailyPace = $currentMonthCollected / $dayOfMonth;
        $endOfMonthProjection = MoneyMath::round($dailyPace * $daysInMonth);

        $growthPct = $this->growthPct($history);
        $projections = [];

        for ($i = 1; $i <= max(1, min($horizonMonths, 6)); $i++) {
            $month = now()->copy()->addMonths($i);
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
            ],
            'projections' => $projections,
            'narrative' => sprintf(
                'Based on the last 3 months, collections are averaging %s. At the current daily pace, this month may reach about %s.',
                number_format($movingAverage, 2),
                number_format($endOfMonthProjection, 2)
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function monthlyHistory(int $tenantId, int $months): array
    {
        $start = now()->subMonths($months - 1)->startOfMonth();
        $payments = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereDate('payment_date', '>=', $start->toDateString())
            ->get();

        $buckets = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');
            $buckets[$key] = [
                'period' => $key,
                'label' => $month->format('M Y'),
                'collected' => 0.0,
            ];
        }

        foreach ($payments as $payment) {
            $key = $payment->payment_date?->format('Y-m');
            if ($key && isset($buckets[$key])) {
                $buckets[$key]['collected'] += (float) $payment->amount;
            }
        }

        return array_values(array_map(function (array $row): array {
            $row['collected'] = MoneyMath::round((float) $row['collected']);

            return $row;
        }, $buckets));
    }

    /**
     * @param array<int, array<string, mixed>> $history
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
