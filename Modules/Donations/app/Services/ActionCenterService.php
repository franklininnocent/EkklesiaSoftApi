<?php

namespace Modules\Donations\Services;

use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlanAssignment;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\ContributionBalance;
use Modules\Family\Models\Family;

class ActionCenterService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildQueues(int $tenantId): array
    {
        return [
            $this->buildFollowUpQueue($tenantId),
            $this->buildOutstandingCollectionsQueue($tenantId),
            $this->buildProjectFundingGapsQueue($tenantId),
            $this->buildUpcomingCollectionDaysQueue($tenantId),
            $this->buildMissingCommitmentsQueue($tenantId),
            $this->buildGivingDeclinesQueue($tenantId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFollowUpQueue(int $tenantId): array
    {
        $today = now()->toDateString();
        $mandatoryCount = ContributionDue::forTenant($tenantId)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereDate('due_date', '<', $today)
            ->distinct('family_id')
            ->count('family_id');

        $projectOverdue = ProjectInstallmentDue::forTenant($tenantId)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereDate('due_date', '<', $today)
            ->distinct('family_id')
            ->count('family_id');

        $affected = $mandatoryCount + $projectOverdue;
        $mandatoryAmount = (float) ContributionDue::forTenant($tenantId)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereDate('due_date', '<', $today)
            ->get()
            ->sum(fn (ContributionDue $due) => ContributionBalance::outstandingForDue($due));

        return [
            'key' => 'families_follow_up',
            'priority' => $affected > 0 ? 'high' : 'low',
            'title' => 'Families Requiring Follow-Up',
            'affected_count' => $affected,
            'expected_amount' => round($mandatoryAmount, 2),
            'summary' => $affected > 0
                ? sprintf('%d families need pastoral or secretary follow-up.', $affected)
                : 'No families currently require urgent follow-up.',
            'suggested_actions' => ['Review Families', 'Send Reminders', 'Assign Volunteer'],
            'cta_route' => '/donations/dues',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOutstandingCollectionsQueue(int $tenantId): array
    {
        $outstanding = ContributionBalance::sumOutstanding(
            ContributionDue::forTenant($tenantId)
        );
        $familyCount = ContributionDue::forTenant($tenantId)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->distinct('family_id')
            ->count('family_id');

        return [
            'key' => 'outstanding_collections',
            'priority' => $outstanding > 0 ? 'medium' : 'low',
            'title' => 'Outstanding Collections',
            'affected_count' => $familyCount,
            'expected_amount' => round($outstanding, 2),
            'summary' => $familyCount > 0
                ? sprintf('%d families have open mandatory balances.', $familyCount)
                : 'Mandatory contribution balances are clear.',
            'suggested_actions' => ['Review Families', 'Collect Payment'],
            'cta_route' => '/donations/dues',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProjectFundingGapsQueue(int $tenantId): array
    {
        $projects = DonationProject::forTenant($tenantId)
            ->where('status', 'active')
            ->get()
            ->filter(function (DonationProject $project): bool {
                $target = (float) $project->target_amount;
                if ($target <= 0) {
                    return false;
                }
                $pct = ((float) $project->raised_amount / $target) * 100;

                return $pct < 50;
            });

        $gap = round($projects->sum(fn (DonationProject $project) => max((float) $project->target_amount - (float) $project->raised_amount, 0)), 2);

        return [
            'key' => 'project_funding_gaps',
            'priority' => $projects->count() > 0 ? 'medium' : 'low',
            'title' => 'Project Funding Gaps',
            'affected_count' => $projects->count(),
            'expected_amount' => $gap,
            'summary' => $projects->count() > 0
                ? sprintf('%d active projects are below 50%% funding.', $projects->count())
                : 'Active projects are on track.',
            'suggested_actions' => ['Review Projects', 'Launch Campaign'],
            'cta_route' => '/donations/projects',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUpcomingCollectionDaysQueue(int $tenantId): array
    {
        $end = now()->addDays(14)->toDateString();
        $today = now()->toDateString();

        $upcoming = ContributionDue::forTenant($tenantId)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereBetween('due_date', [$today, $end])
            ->get();

        $expected = round($upcoming->sum(fn (ContributionDue $due) => ContributionBalance::outstandingForDue($due)), 2);
        $familyCount = $upcoming->pluck('family_id')->unique()->count();

        return [
            'key' => 'upcoming_collection_days',
            'priority' => $upcoming->count() > 0 ? 'medium' : 'low',
            'title' => 'Upcoming Collection Days',
            'affected_count' => $familyCount,
            'expected_amount' => $expected,
            'summary' => $upcoming->count() > 0
                ? sprintf('%d period dues are due in the next 14 days.', $upcoming->count())
                : 'No major collection days in the next two weeks.',
            'suggested_actions' => ['View Schedule', 'Prepare Collection Day'],
            'cta_route' => '/donations/collection-day',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMissingCommitmentsQueue(int $tenantId): array
    {
        $fyStart = now()->startOfYear()->toDateString();
        $assignments = ContributionPlanAssignment::forTenant($tenantId)
            ->where('status', 'active')
            ->get();

        $missing = $assignments->filter(function (ContributionPlanAssignment $assignment) use ($tenantId, $fyStart): bool {
            if (!$assignment->family_id) {
                return false;
            }

            $paid = (float) DonationPayment::forTenant($tenantId)
                ->where('family_id', $assignment->family_id)
                ->where('status', 'succeeded')
                ->whereDate('payment_date', '>=', $fyStart)
                ->sum('amount');

            return $paid <= 0;
        });

        return [
            'key' => 'missing_commitments',
            'priority' => $missing->count() > 0 ? 'medium' : 'low',
            'title' => 'Missing Payment Commitments',
            'affected_count' => $missing->count(),
            'expected_amount' => round((float) $missing->sum('amount'), 2),
            'summary' => $missing->count() > 0
                ? sprintf('%d assigned families have not contributed this financial year.', $missing->count())
                : 'Assigned families are participating this year.',
            'suggested_actions' => ['Review Assignments', 'Send Reminder'],
            'cta_route' => '/donations/plans',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGivingDeclinesQueue(int $tenantId): array
    {
        $recentStart = now()->subDays(90)->toDateString();
        $priorStart = now()->subDays(180)->toDateString();
        $priorEnd = now()->subDays(91)->toDateString();

        $recentByFamily = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereDate('payment_date', '>=', $recentStart)
            ->select('family_id', DB::raw('SUM(amount) as total'))
            ->groupBy('family_id')
            ->pluck('total', 'family_id');

        $priorByFamily = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereBetween('payment_date', [$priorStart, $priorEnd])
            ->select('family_id', DB::raw('SUM(amount) as total'))
            ->groupBy('family_id')
            ->pluck('total', 'family_id');

        $declines = 0;
        foreach ($priorByFamily as $familyId => $priorTotal) {
            $prior = (float) $priorTotal;
            if ($prior <= 0) {
                continue;
            }
            $recent = (float) ($recentByFamily[$familyId] ?? 0);
            if ($recent < ($prior * 0.7)) {
                $declines++;
            }
        }

        return [
            'key' => 'giving_declines',
            'priority' => $declines > 0 ? 'medium' : 'low',
            'title' => 'Recent Declines in Giving',
            'affected_count' => $declines,
            'expected_amount' => 0,
            'summary' => $declines > 0
                ? sprintf('%d families gave significantly less in the last 90 days.', $declines)
                : 'No notable giving declines detected.',
            'suggested_actions' => ['View Families', 'Pastoral Outreach'],
            'cta_route' => '/donations/donors',
        ];
    }
}
