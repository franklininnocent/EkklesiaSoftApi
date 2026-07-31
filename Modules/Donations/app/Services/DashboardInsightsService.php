<?php

namespace Modules\Donations\Services;

class DashboardInsightsService
{
    /**
     * @param array<string, mixed> $summary
     * @return array<int, array<string, mixed>>
     */
    public function buildProactiveInsights(array $summary): array
    {
        $insights = [];
        $growth = (float) ($summary['kpis']['collection_growth_pct'] ?? 0);
        $attentionCount = (int) ($summary['attention_summary']['count'] ?? 0);
        $overdueAmount = (float) ($summary['attention_summary']['total_overdue_amount'] ?? 0);
        $participation = (float) ($summary['families']['participation_rate'] ?? 0);
        $pendingDues = (float) ($summary['totals']['pending_dues'] ?? 0);
        $currency = $summary['tenant_context']['currency_code'] ?? 'INR';

        if ($growth >= 5) {
            $insights[] = [
                'severity' => 'opportunity',
                'title' => 'Collection momentum is strong',
                'body' => sprintf('Collections grew %.1f%% compared to last month.', $growth),
                'action' => ['label' => 'Review trend', 'section' => 'analytics'],
            ];
        } elseif ($growth <= -5) {
            $insights[] = [
                'severity' => 'risk',
                'title' => 'Collection decline detected',
                'body' => sprintf('Collections fell %.1f%% versus last month. Review follow-up priorities.', abs($growth)),
                'action' => ['label' => 'Review families', 'section' => 'action_center'],
            ];
        }

        if ($attentionCount > 0) {
            $insights[] = [
                'severity' => $attentionCount >= 10 ? 'risk' : 'info',
                'title' => sprintf('%d %s overdue on mandatory contributions', $attentionCount, $attentionCount === 1 ? 'family is' : 'families are'),
                'body' => sprintf('%s %s in overdue mandatory dues requires follow-up.', $currency, number_format($overdueAmount, 2)),
                'action' => ['label' => 'Open attention queue', 'section' => 'action_center'],
            ];
        }

        if ($participation < 60 && ($summary['families']['active'] ?? 0) > 0) {
            $insights[] = [
                'severity' => 'risk',
                'title' => 'Family engagement needs attention',
                'body' => sprintf('Only %.1f%% of active families contributed in the last 90 days.', $participation),
                'action' => ['label' => 'View families', 'section' => 'action_center'],
            ];
        }

        $projects = $summary['active_project_summaries'] ?? [];
        foreach ($projects as $project) {
            $pct = (float) ($project['funding_percentage'] ?? 0);
            if ($pct >= 80) {
                $insights[] = [
                    'severity' => 'opportunity',
                    'title' => sprintf('%s is nearing its goal', $project['name']),
                    'body' => sprintf('Funding is at %.1f%% — consider a final push or celebration milestone.', $pct),
                    'action' => ['label' => 'View projects', 'section' => 'projects_command'],
                ];
                break;
            }
            if ($pct < 40 && ($project['target_amount'] ?? 0) > 0) {
                $insights[] = [
                    'severity' => 'risk',
                    'title' => sprintf('%s is behind target', $project['name']),
                    'body' => sprintf('Only %.1f%% funded with %s %s remaining.', $pct, $currency, number_format((float) $project['remaining'], 2)),
                    'action' => ['label' => 'Review project', 'section' => 'projects_command'],
                ];
                break;
            }
        }

        $compliance = (float) ($summary['kpis']['plan_compliance_pct'] ?? 0);
        if ($compliance > 0 && $compliance < 70) {
            $insights[] = [
                'severity' => 'info',
                'title' => 'Contribution plan compliance is below target',
                'body' => sprintf('Plan compliance is at %.1f%% for the current period.', $compliance),
                'action' => ['label' => 'View outstanding', 'section' => 'action_center'],
            ];
        }

        if ($pendingDues > 0 && $attentionCount === 0) {
            $insights[] = [
                'severity' => 'info',
                'title' => 'Outstanding balance without overdue families',
                'body' => sprintf('%s %s is outstanding but not yet overdue.', $currency, number_format($pendingDues, 2)),
                'action' => ['label' => 'View dues', 'section' => 'action_center'],
            ];
        }

        return array_slice($insights, 0, 6);
    }
}
