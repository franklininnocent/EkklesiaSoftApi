<?php

namespace Modules\Donations\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\PaymentAllocation;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\DashboardBccFilter;
use Modules\Donations\Support\DashboardDateRange;
use Modules\Donations\Support\DashboardProjectFilter;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;

/**
 * Parish dashboard snapshot. Amounts reuse the existing collected, due, and participation rules.
 */
class DonationDashboardSnapshotBuilder
{
    public function __construct(
        private readonly ExecutiveReportMetricsService $executiveMetrics
    ) {}

    /**
     * @param  array<string, mixed>  $periodCollections
     * @param  array<string, mixed>  $families
     * @param  array<string, mixed>  $growthAnalysis
     * @param  array{families: array<int, array<string, mixed>>, count: int, total_overdue_amount: float}  $attention
     * @param  array<int, array<string, mixed>>  $recentActivity
     * @return array<string, mixed>
     */
    public function build(
        int $tenantId,
        array $periodCollections,
        array $families,
        array $growthAnalysis,
        array $attention,
        array $recentActivity,
        float $outstandingContributions,
        int $activeProjectCount,
        ?DashboardDateRange $range = null,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): array {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $parishToday = DonationBusinessDate::today($tenantId);
        $businessDate = $range?->asOf ?? $parishToday;
        $fyBounds = DonationBusinessDate::currentFinancialYearBounds($tenantId, $parishToday);
        if ($range !== null) {
            $comparisonRange = [
                'start' => $range->comparisonStart,
                'end' => $range->comparisonEnd,
            ];
            $participationWindow = [
                'start' => $range->dateFrom,
                'end' => $range->collectionEnd,
            ];
            $givingStart = $range->dateFrom;
            $givingEnd = $range->collectionEnd;
            $givingTotal = MoneyMath::normalize($periodCollections['current_month_collected'] ?? 0);
        } else {
            $comparisonRange = $this->executiveMetrics->comparablePreviousMonthCollectionRange($tenantId);
            $participationWindow = $this->executiveMetrics->participationWindow($tenantId);
            $givingStart = $fyBounds['start'];
            $givingEnd = $businessDate;
            $givingTotal = MoneyMath::normalize($periodCollections['annual_collected'] ?? 0);
        }
        $dueSchedule = $this->executiveMetrics->dueSchedulePartition($tenantId, $range, $bccFilter, $projectFilter);
        $dueNext = [
            'amount' => MoneyMath::toApiNumber($dueSchedule['next_14_days_amount']),
            'families' => (int) $dueSchedule['next_14_days_family_count'],
        ];
        $installments = $this->projectInstallmentSplit($tenantId, $businessDate, $bccFilter, $projectFilter);
        $fiscalYearCollected = MoneyMath::normalize($periodCollections['annual_collected'] ?? 0);
        $comparisonCollected = MoneyMath::normalize($growthAnalysis['previous_collected'] ?? 0);
        $active = (int) ($families['active'] ?? 0);
        $participating = (int) ($families['participating_last_90_days'] ?? 0);

        return [
            'as_of' => $businessDate,
            'timezone' => DonationBusinessDate::timezoneForTenant($tenantId),
            'due_next_14_days_basis' => 'parish_today',
            'applied_range' => $range?->appliedRangePayload(),
            'metric_basis' => $range?->metricBasisPayload(),
            'financial_year' => (string) ($periodCollections['financial_year'] ?? ''),
            'financial_year_key' => (string) ($periodCollections['financial_year_key'] ?? ''),
            'financial_year_start' => $fyBounds['start'],
            'financial_year_end' => $fyBounds['end'],
            'month' => [
                'collected' => MoneyMath::toApiNumber($periodCollections['current_month_collected'] ?? 0),
                'comparison_start' => $comparisonRange['start'],
                'comparison_end' => $comparisonRange['end'],
                'comparison_collected' => MoneyMath::toApiNumber($comparisonCollected),
                // Null when the same-days window last month collected nothing. A 0% change is a real comparison.
                'growth_pct' => MoneyMath::equals($comparisonCollected, '0')
                    ? null
                    : (float) ($growthAnalysis['growth_pct'] ?? 0),
            ],
            'fiscal_year_collected' => MoneyMath::toApiNumber($fiscalYearCollected),
            'outstanding_contributions' => MoneyMath::toApiNumber($outstandingContributions),
            'overdue_amount' => MoneyMath::toApiNumber($attention['total_overdue_amount'] ?? 0),
            'overdue_families' => (int) ($attention['count'] ?? 0),
            'due_next_14_days_amount' => $dueNext['amount'],
            'due_next_14_days_families' => $dueNext['families'],
            'participation' => [
                'participating' => $participating,
                'active' => $active,
                'rate' => (float) ($families['participation_rate'] ?? 0),
                'window_start' => $participationWindow['start'],
                'window_end' => $participationWindow['end'],
                'not_participating' => max(0, $active - $participating),
                'net_change_vs_prior_window' => (int) ($families['contributing_families_delta'] ?? 0),
            ],
            'giving_mix' => $this->givingMix($tenantId, $givingStart, $givingEnd, $givingTotal, $bccFilter, $projectFilter),
            'project_installments' => $installments,
            'active_project_count' => $activeProjectCount,
            'projects' => $this->projectRows($tenantId, $projectFilter),
            'attention_families' => array_slice($attention['families'] ?? [], 0, 5),
            'recent_payments' => array_slice($recentActivity, 0, 5),
        ];
    }

    /**
     * @return array{overdue: float, not_yet_due: float, open: float}
     */
    private function projectInstallmentSplit(
        int $tenantId,
        string $businessDate,
        DashboardBccFilter $bccFilter,
        DashboardProjectFilter $projectFilter
    ): array {
        $overdue = $this->installmentOutstanding($tenantId, function (Builder $query) use ($businessDate): void {
            $query->whereDate('due_date', '<', $businessDate);
        }, $bccFilter, $projectFilter);
        $notYetDue = $this->installmentOutstanding($tenantId, function (Builder $query) use ($businessDate): void {
            $query->whereDate('due_date', '>=', $businessDate);
        }, $bccFilter, $projectFilter);

        return [
            'overdue' => MoneyMath::toApiNumber($overdue),
            'not_yet_due' => MoneyMath::toApiNumber($notYetDue),
            'open' => MoneyMath::toApiNumber(MoneyMath::add($overdue, $notYetDue)),
        ];
    }

    private function installmentOutstanding(
        int $tenantId,
        callable $constrain,
        DashboardBccFilter $bccFilter,
        DashboardProjectFilter $projectFilter
    ): string {
        $query = ProjectInstallmentDue::forTenant($tenantId);
        $constrain($query);
        if ($projectFilter->isActive) {
            $projectFilter->applyToProjectInstallmentDueQuery($query, $tenantId);
            $this->executiveMetrics->applyDashboardFamilyScope($query, $tenantId, $bccFilter, $projectFilter);
        } elseif ($bccFilter->isActive) {
            $this->executiveMetrics->applyDashboardFamilyScope($query, $tenantId, $bccFilter, $projectFilter);
        }

        return MoneyMath::normalize(
            ContributionBalance::sumFlooredOutstanding($query)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function givingMix(
        int $tenantId,
        string $fyStart,
        string $businessDate,
        string $fiscalYearCollected,
        DashboardBccFilter $bccFilter,
        DashboardProjectFilter $projectFilter
    ): array {
        $query = PaymentAllocation::query()
            ->forTenant($tenantId)
            ->join('donation_payments', 'donation_payments.id', '=', 'payment_allocations.payment_id')
            ->where('donation_payments.tenant_id', $tenantId)
            ->where('donation_payments.status', 'succeeded')
            ->whereNull('donation_payments.deleted_at')
            ->whereNull('payment_allocations.deleted_at')
            ->whereDate('donation_payments.payment_date', '>=', $fyStart)
            ->whereDate('donation_payments.payment_date', '<=', $businessDate);
        $projectFilter->applyToPaymentAllocationQuery($query, $tenantId);
        $paymentScope = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereDate('payment_date', '>=', $fyStart)
            ->whereDate('payment_date', '<=', $businessDate);
        $bccFilter->applyToDonationPaymentQuery($paymentScope, $tenantId);
        if ($projectFilter->isActive) {
            $projectFilter->applyToDonationPaymentQuery($paymentScope, $tenantId);
        }
        $query->whereIn('payment_allocations.payment_id', $paymentScope->select('id'));

        $rows = $query
            ->selectRaw('payment_allocations.allocatable_type as allocatable_type, COALESCE(SUM(payment_allocations.amount), 0) as total')
            ->groupBy('payment_allocations.allocatable_type')
            ->get();

        $buckets = [
            'contribution_dues' => '0.00',
            'projects' => '0.00',
            'voluntary' => '0.00',
            'other' => '0.00',
        ];

        foreach ($rows as $row) {
            $key = $this->givingMixKey((string) $row->allocatable_type);
            $buckets[$key] = MoneyMath::add($buckets[$key], $row->total);
        }

        $allocated = '0.00';
        foreach ($buckets as $amount) {
            $allocated = MoneyMath::add($allocated, $amount);
        }
        $unallocated = MoneyMath::subtract($fiscalYearCollected, $allocated);

        return [
            'buckets' => [
                ['key' => 'contribution_dues', 'label' => 'Contribution dues', 'amount' => MoneyMath::toApiNumber($buckets['contribution_dues'])],
                ['key' => 'projects', 'label' => 'Projects', 'amount' => MoneyMath::toApiNumber($buckets['projects'])],
                ['key' => 'voluntary', 'label' => 'Voluntary gifts', 'amount' => MoneyMath::toApiNumber($buckets['voluntary'])],
                ['key' => 'other', 'label' => 'Other', 'amount' => MoneyMath::toApiNumber($buckets['other'])],
            ],
            'unallocated' => MoneyMath::toApiNumber($unallocated),
            'reconciled' => MoneyMath::equals(MoneyMath::add($allocated, $unallocated), $fiscalYearCollected),
        ];
    }

    private function givingMixKey(string $allocatableType): string
    {
        return match ($allocatableType) {
            'due' => 'contribution_dues',
            'project', 'project_installment' => 'projects',
            'donation' => 'voluntary',
            default => 'other',
        };
    }

    /**
     * Active projects with the stored target. A zero target stays zero and has no funding percentage.
     *
     * @return array<int, array<string, mixed>>
     */
    private function projectRows(int $tenantId, DashboardProjectFilter $projectFilter): array
    {
        $query = DonationProject::forTenant($tenantId)->where('status', 'active');
        if ($projectFilter->isActive && $projectFilter->projectId !== null) {
            $query->where('id', $projectFilter->projectId);
        }

        return $query
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(function (DonationProject $project): array {
                $target = MoneyMath::normalize($project->target_amount);
                $raised = MoneyMath::normalize($project->raised_amount);
                $hasTarget = MoneyMath::compare($target, '0') > 0;
                $remaining = $hasTarget ? MoneyMath::floorAtZero(MoneyMath::subtract($target, $raised)) : null;
                $percentage = null;
                if ($hasTarget) {
                    $ratio = ((float) $raised / (float) $target) * 100;
                    $percentage = round(min(100, $ratio), 1);
                }

                return [
                    'project_id' => $project->id,
                    'name' => $project->name,
                    'code' => $project->code,
                    'target_amount' => MoneyMath::toApiNumber($target),
                    'has_target' => $hasTarget,
                    'collected' => MoneyMath::toApiNumber($raised),
                    'remaining' => $remaining === null ? null : MoneyMath::toApiNumber($remaining),
                    'funding_percentage' => $percentage,
                ];
            })
            ->values()
            ->all();
    }
}
