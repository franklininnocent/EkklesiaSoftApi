<?php

namespace Modules\Donations\Services;

use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;

class DashboardPersonaService
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(User $user, ?array $tenantContext = null): array
    {
        $persona = $this->detectPersona($user, $tenantContext);
        $sections = $this->sectionsForPersona($persona);

        return [
            'persona' => $persona,
            'label' => $this->labelForPersona($persona),
            'emphasis' => $this->emphasisForPersona($persona),
            'sections' => $sections,
            'default_dashboard_view' => $persona === 'diocese_officer' ? 'rollup' : 'local',
            'quick_actions' => $this->quickActionsForPersona($persona),
        ];
    }

    /**
     * @param array<string, mixed>|null $tenantContext
     */
    private function detectPersona(User $user, ?array $tenantContext): string
    {
        $roleName = strtolower(trim((string) ($user->role?->name ?? $user->role_name ?? '')));
        $tier = strtolower((string) ($tenantContext['tier'] ?? ''));

        if (in_array($tier, ['diocese', 'organization'], true) && $this->userCan($user, 'donations.reports')) {
            return 'diocese_officer';
        }

        if ($this->matchesRole($roleName, ['priest', 'pastor', 'clergy', 'rector', 'vicar'])) {
            return 'priest';
        }

        if ($this->matchesRole($roleName, ['treasurer', 'finance', 'accountant'])) {
            return 'treasurer';
        }

        if ($this->matchesRole($roleName, ['secretary', 'administrator', 'church admin', 'parish secretary'])) {
            return 'secretary';
        }

        if ($this->userCan($user, 'donations.reports') && !$this->userCan($user, 'donations.collect')) {
            return 'treasurer';
        }

        if ($this->userCan($user, 'donations.collect') && !$this->userCan($user, 'donations.reports')) {
            return 'secretary';
        }

        return 'admin';
    }

    /**
     * @param array<int, string> $needles
     */
    private function matchesRole(string $roleName, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($roleName, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function userCan(User $user, string $permission): bool
    {
        return method_exists($user, 'hasPermission') && $user->hasPermission($permission);
    }

    /**
     * @return array<int, string>
     */
    private function sectionsForPersona(string $persona): array
    {
        return match ($persona) {
            'priest' => [
                'health_overview',
                'layer_2_health',
                'action_center',
                'layer_3_actions',
                'analytics',
                'layer_4_analytics',
                'operational_intelligence',
                'layer_5_intelligence',
                'ai_advisor',
                'collections_command',
                'projects_command',
            ],
            'treasurer' => [
                'health_overview',
                'layer_2_health',
                'action_center',
                'layer_3_actions',
                'analytics',
                'layer_4_analytics',
                'operational_intelligence',
                'layer_5_intelligence',
                'ai_advisor',
                'contribution_intelligence',
                'collections_command',
                'projects_command',
                'communication_center',
            ],
            'secretary' => [
                'health_overview',
                'layer_2_health',
                'action_center',
                'layer_3_actions',
                'collections_command',
                'communication_center',
                'operational_intelligence',
                'layer_5_intelligence',
            ],
            'diocese_officer' => [
                'health_overview',
                'layer_2_health',
                'diocese_rollup',
                'analytics',
                'layer_4_analytics',
                'ai_advisor',
            ],
            default => [
                'health_overview',
                'layer_2_health',
                'action_center',
                'layer_3_actions',
                'analytics',
                'layer_4_analytics',
                'operational_intelligence',
                'layer_5_intelligence',
                'ai_advisor',
                'contribution_intelligence',
                'collections_command',
                'projects_command',
                'communication_center',
            ],
        };
    }

    private function labelForPersona(string $persona): string
    {
        return match ($persona) {
            'priest' => 'Pastoral View',
            'treasurer' => 'Treasurer View',
            'secretary' => 'Secretary View',
            'diocese_officer' => 'Diocese Officer View',
            default => 'Administrator View',
        };
    }

    private function emphasisForPersona(string $persona): string
    {
        return match ($persona) {
            'priest' => 'Families needing care and active project momentum come first.',
            'treasurer' => 'Collections, trends, and forecasts for financial stewardship.',
            'secretary' => 'Fast family follow-up, receipts, and daily collections.',
            'diocese_officer' => 'Parish comparisons and consolidated diocese performance.',
            default => 'Full financial visibility across the parish.',
        };
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function quickActionsForPersona(string $persona): array
    {
        return match ($persona) {
            'priest' => [
                ['id' => 'attention', 'label' => 'Review overdue families'],
                ['id' => 'projects', 'label' => 'Open active projects'],
            ],
            'treasurer' => [
                ['id' => 'reports', 'label' => 'Open reports'],
                ['id' => 'forecast', 'label' => 'Review forecast'],
            ],
            'secretary' => [
                ['id' => 'collect', 'label' => 'Quick collect'],
                ['id' => 'receipts', 'label' => 'Receipts hub'],
            ],
            'diocese_officer' => [
                ['id' => 'rollup', 'label' => 'Diocese rollup'],
                ['id' => 'parishes', 'label' => 'Compare parishes'],
            ],
            default => [
                ['id' => 'collect', 'label' => 'Quick collect'],
                ['id' => 'dashboard', 'label' => 'Refresh dashboard'],
            ],
        };
    }
}
