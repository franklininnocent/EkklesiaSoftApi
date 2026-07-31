<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Support\ContributionBalance;
use Modules\Family\Models\Family;

class DonationSavedViewService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function presets(): array
    {
        return [
            [
                'key' => 'outstanding_families',
                'label' => 'Outstanding Families',
                'description' => 'Families with pending or overdue mandatory contributions.',
                'route' => '/donations/dues',
            ],
            [
                'key' => 'high_risk_families',
                'label' => 'High-Risk Families',
                'description' => 'Families with the largest overdue exposure.',
                'route' => '/donations',
                'dashboard_section' => 'families_attention',
            ],
            [
                'key' => 'recent_contributors',
                'label' => 'Recent Contributors',
                'description' => 'Families who paid in the last 30 days.',
                'route' => '/donations/payments',
            ],
            [
                'key' => 'active_projects',
                'label' => 'Active Projects',
                'description' => 'Fundraising projects currently in progress.',
                'route' => '/donations/projects',
            ],
            [
                'key' => 'building_fund_donors',
                'label' => 'Building Fund Donors',
                'description' => 'Families contributing to building or construction projects.',
                'route' => '/donations/projects',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(int $tenantId, string $key, int $limit = 20): array
    {
        return match ($key) {
            'outstanding_families' => $this->outstandingFamilies($tenantId, $limit),
            'high_risk_families' => $this->highRiskFamilies($tenantId, $limit),
            'recent_contributors' => $this->recentContributors($tenantId, $limit),
            'active_projects' => $this->activeProjects($tenantId, $limit),
            'building_fund_donors' => $this->buildingFundDonors($tenantId, $limit),
            default => [
                'key' => $key,
                'available' => false,
                'message' => 'Saved view not found.',
                'items' => [],
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function outstandingFamilies(int $tenantId, int $limit): array
    {
        $dues = ContributionDue::forTenant($tenantId)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->with('family:id,family_name,family_code')
            ->get();

        $items = $dues->groupBy('family_id')->map(function ($familyDues) {
            $first = $familyDues->first();

            return [
                'family_id' => $first->family_id,
                'family_name' => $first->family?->family_name,
                'family_code' => $first->family?->family_code,
                'outstanding_amount' => round((float) $familyDues->sum(fn ($due) => ContributionBalance::outstandingForDue($due)), 2),
            ];
        })->sortByDesc('outstanding_amount')->values()->take($limit)->all();

        return [
            'key' => 'outstanding_families',
            'available' => true,
            'label' => 'Outstanding Families',
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function highRiskFamilies(int $tenantId, int $limit): array
    {
        $today = now()->toDateString();
        $dues = ContributionDue::forTenant($tenantId)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereDate('due_date', '<', $today)
            ->with('family:id,family_name,family_code')
            ->get();

        $items = $dues->groupBy('family_id')->map(function ($familyDues) {
            $first = $familyDues->first();

            return [
                'family_id' => $first->family_id,
                'family_name' => $first->family?->family_name,
                'family_code' => $first->family?->family_code,
                'overdue_amount' => round((float) $familyDues->sum(fn ($due) => ContributionBalance::outstandingForDue($due)), 2),
            ];
        })->sortByDesc('overdue_amount')->values()->take($limit)->all();

        return [
            'key' => 'high_risk_families',
            'available' => true,
            'label' => 'High-Risk Families',
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recentContributors(int $tenantId, int $limit): array
    {
        $items = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereDate('payment_date', '>=', now()->subDays(30)->toDateString())
            ->with('family:id,family_name,family_code')
            ->orderByDesc('payment_date')
            ->limit($limit)
            ->get()
            ->groupBy('family_id')
            ->map(function ($payments) {
                $first = $payments->first();

                return [
                    'family_id' => $first->family_id,
                    'family_name' => $first->family?->family_name,
                    'family_code' => $first->family?->family_code,
                    'total_paid' => round((float) $payments->sum('amount'), 2),
                    'payment_count' => $payments->count(),
                ];
            })
            ->sortByDesc('total_paid')
            ->values()
            ->all();

        return [
            'key' => 'recent_contributors',
            'available' => true,
            'label' => 'Recent Contributors',
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function activeProjects(int $tenantId, int $limit): array
    {
        $items = DonationProject::forTenant($tenantId)
            ->where('status', 'active')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (DonationProject $project) => [
                'project_id' => $project->id,
                'name' => $project->name,
                'code' => $project->code,
                'target_amount' => round((float) $project->target_amount, 2),
                'collected' => round((float) $project->raised_amount, 2),
                'funding_percentage' => round(min(100, ((float) $project->raised_amount / max((float) $project->target_amount, 1)) * 100), 1),
            ])
            ->values()
            ->all();

        return [
            'key' => 'active_projects',
            'available' => true,
            'label' => 'Active Projects',
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildingFundDonors(int $tenantId, int $limit): array
    {
        $projectIds = DonationProject::forTenant($tenantId)
            ->where('status', 'active')
            ->where(function ($builder): void {
                $builder->where('name', 'like', '%building%')
                    ->orWhere('name', 'like', '%construction%')
                    ->orWhere('code', 'like', '%BLDG%');
            })
            ->pluck('id');

        if ($projectIds->isEmpty()) {
            return [
                'key' => 'building_fund_donors',
                'available' => true,
                'label' => 'Building Fund Donors',
                'count' => 0,
                'items' => [],
            ];
        }

        $items = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereIn('source_type', ['project', 'general'])
            ->with('family:id,family_name,family_code')
            ->orderByDesc('payment_date')
            ->limit($limit * 3)
            ->get()
            ->groupBy('family_id')
            ->map(function ($payments) {
                $first = $payments->first();

                return [
                    'family_id' => $first->family_id,
                    'family_name' => $first->family?->family_name,
                    'family_code' => $first->family?->family_code,
                    'total_paid' => round((float) $payments->sum('amount'), 2),
                ];
            })
            ->sortByDesc('total_paid')
            ->values()
            ->take($limit)
            ->all();

        return [
            'key' => 'building_fund_donors',
            'available' => true,
            'label' => 'Building Fund Donors',
            'count' => count($items),
            'items' => $items,
        ];
    }
}
