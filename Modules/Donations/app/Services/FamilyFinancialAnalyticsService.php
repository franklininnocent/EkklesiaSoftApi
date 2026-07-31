<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\DonationPayment;
use Modules\Family\Models\Family;

class FamilyFinancialAnalyticsService
{
    /**
     * @param array<string, mixed> $mandatory
     * @param array{total_paid: float, mandatory_paid: float, project_paid: float, voluntary_paid: float} $paymentBreakdown
     * @return array<string, mixed>
     */
    public function build(int $tenantId, string $familyId, array $mandatory, array $paymentBreakdown): array
    {
        return [
            'trend' => $this->buildTrend($tenantId, $familyId),
            'punctuality' => $this->buildPunctuality($tenantId, $familyId),
            'ranking' => $this->buildRanking($tenantId, $familyId, $paymentBreakdown['total_paid']),
            'comparison' => $this->buildComparison($tenantId, $familyId, $paymentBreakdown['total_paid'], $mandatory),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTrend(int $tenantId, string $familyId): array
    {
        $start = now()->subMonths(11)->startOfMonth();
        $payments = DonationPayment::forTenant($tenantId)
            ->where('family_id', $familyId)
            ->where('status', 'succeeded')
            ->whereDate('payment_date', '>=', $start->toDateString())
            ->with('allocations')
            ->get();

        $buckets = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');
            $buckets[$key] = [
                'period' => $key,
                'label' => $month->format('M Y'),
                'mandatory_paid' => 0.0,
                'project_paid' => 0.0,
                'voluntary_paid' => 0.0,
                'total_paid' => 0.0,
            ];
        }

        foreach ($payments as $payment) {
            $key = $payment->payment_date?->format('Y-m');
            if (!$key || !isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['total_paid'] += (float) $payment->amount;
            $allocated = 0.0;

            foreach ($payment->allocations as $allocation) {
                if ($allocation->allocatable_type === 'advance') {
                    continue;
                }

                $amount = (float) $allocation->amount;
                $allocated += $amount;

                match ($allocation->allocatable_type) {
                    'due' => $buckets[$key]['mandatory_paid'] += $amount,
                    'project', 'project_installment' => $buckets[$key]['project_paid'] += $amount,
                    'donation' => $buckets[$key]['voluntary_paid'] += $amount,
                    default => null,
                };
            }

            $unallocated = max(0, (float) $payment->amount - $allocated);
            if ($unallocated > 0) {
                $buckets[$key]['voluntary_paid'] += $unallocated;
            }
        }

        return array_values(array_map(function (array $row): array {
            $row['mandatory_paid'] = round($row['mandatory_paid'], 2);
            $row['project_paid'] = round($row['project_paid'], 2);
            $row['voluntary_paid'] = round($row['voluntary_paid'], 2);
            $row['total_paid'] = round($row['total_paid'], 2);

            return $row;
        }, $buckets));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPunctuality(int $tenantId, string $familyId): array
    {
        $dues = ContributionDue::forTenant($tenantId)
            ->where('family_id', $familyId)
            ->whereDate('due_date', '<=', now()->toDateString())
            ->get();

        $evaluated = $dues->count();
        $paidOnTime = $dues->where('status', 'paid')->count();
        $overdue = $dues->filter(function (ContributionDue $due): bool {
            if (!in_array($due->status, ['pending', 'partially_paid'], true)) {
                return false;
            }

            return $due->due_date && $due->due_date->lt(now()->startOfDay());
        })->count();
        $partiallyPaidLate = $dues->where('status', 'partially_paid')
            ->filter(fn (ContributionDue $due) => $due->due_date && $due->due_date->lt(now()->startOfDay()))
            ->count();

        $score = $evaluated > 0
            ? round(($paidOnTime / $evaluated) * 100, 1)
            : 100.0;

        return [
            'score' => $score,
            'evaluated_periods' => $evaluated,
            'paid_on_time' => $paidOnTime,
            'overdue_open' => $overdue,
            'partially_paid_late' => $partiallyPaidLate,
            'label' => match (true) {
                $score >= 90 => 'Excellent',
                $score >= 75 => 'Good',
                $score >= 50 => 'Fair',
                default => 'Needs Attention',
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRanking(int $tenantId, string $familyId, float $familyTotalPaid): array
    {
        $familyTotals = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->get()
            ->groupBy('family_id')
            ->map(fn ($payments) => (float) $payments->sum('amount'))
            ->sortDesc();

        $activeFamilies = Family::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->count();

        $rankByGiving = 1;
        $found = false;
        $index = 0;
        foreach ($familyTotals as $rankedFamilyId => $totalPaid) {
            if ($rankedFamilyId === $familyId) {
                $rankByGiving = $index + 1;
                $found = true;
                break;
            }
            $index++;
        }

        if (!$found && $familyTotalPaid <= 0) {
            $rankByGiving = $activeFamilies > 0 ? $activeFamilies : 1;
        }

        $participatingFamilies = max($familyTotals->count(), 1);
        $percentile = round((1 - (($rankByGiving - 1) / $participatingFamilies)) * 100, 1);

        return [
            'by_total_giving' => $rankByGiving,
            'participating_families' => $participatingFamilies,
            'active_families' => $activeFamilies,
            'percentile' => max(0, min(100, $percentile)),
            'total_paid' => round($familyTotalPaid, 2),
        ];
    }

    /**
     * @param array<string, mixed> $mandatory
     * @return array<string, mixed>
     */
    private function buildComparison(int $tenantId, string $familyId, float $familyTotalPaid, array $mandatory): array
    {
        $familyTotals = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->get()
            ->groupBy('family_id')
            ->map(fn ($payments) => (float) $payments->sum('amount'))
            ->values();

        $tenantAverage = (float) ($familyTotals->avg() ?? 0);
        $tenantMedian = $this->medianFromCollection($familyTotals);

        $vsAveragePct = $tenantAverage > 0
            ? round((($familyTotalPaid - $tenantAverage) / $tenantAverage) * 100, 1)
            : 0.0;
        $vsMedianPct = $tenantMedian > 0
            ? round((($familyTotalPaid - $tenantMedian) / $tenantMedian) * 100, 1)
            : 0.0;

        $familyPendingTotals = ContributionDue::forTenant($tenantId)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->get()
            ->groupBy('family_id')
            ->map(fn ($dues) => (float) $dues->sum(fn (ContributionDue $due) => max((float) $due->amount_due - (float) $due->amount_paid, 0)))
            ->values();

        $tenantMandatoryPendingAvg = (float) ($familyPendingTotals->avg() ?? 0);

        return [
            'tenant_average_giving' => round($tenantAverage, 2),
            'tenant_median_giving' => round($tenantMedian, 2),
            'family_total_giving' => round($familyTotalPaid, 2),
            'vs_average_pct' => $vsAveragePct,
            'vs_median_pct' => $vsMedianPct,
            'family_mandatory_pending' => round($mandatory['totals']['pending'] ?? 0, 2),
            'tenant_average_mandatory_pending' => round(max(0, $tenantMandatoryPendingAvg), 2),
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, float> $values
     */
    private function medianFromCollection($values): float
    {
        $sorted = $values->sort()->values();
        $count = $sorted->count();
        if ($count === 0) {
            return 0.0;
        }

        $middle = intdiv($count, 2);
        if ($count % 2 === 1) {
            return (float) $sorted[$middle];
        }

        return ((float) $sorted[$middle - 1] + (float) $sorted[$middle]) / 2;
    }
}
