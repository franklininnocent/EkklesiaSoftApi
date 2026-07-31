<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\DonationProject;

class DonationCampaignService
{
    public function __construct(private readonly DonationProjectService $projectService)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $tenantId): array
    {
        return DonationProject::forTenant($tenantId)
            ->where('entity_kind', 'campaign')
            ->with('fund')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (DonationProject $campaign) use ($tenantId): array {
                $dashboard = $this->projectService->getDashboard($tenantId, $campaign);

                return array_merge($campaign->toArray(), [
                    'collection_percentage' => $dashboard['totals']['collection_percentage'],
                    'families_enrolled' => $dashboard['families']['enrolled'],
                ]);
            })
            ->values()
            ->all();
    }

    public function create(int $tenantId, int $userId, array $payload): DonationProject
    {
        $payload['entity_kind'] = 'campaign';
        $payload['campaign_type'] = $payload['campaign_type'] ?? 'general';
        $payload['assignment_mode'] = 'uniform';
        $payload['default_family_target'] = $payload['default_family_target'] ?? 0;
        $payload['auto_generate_installments'] = $payload['auto_generate_installments'] ?? false;
        $payload['status'] = $payload['status'] ?? 'active';

        return $this->projectService->create($tenantId, $userId, $payload);
    }

    public function find(int $tenantId, string $id): DonationProject
    {
        return DonationProject::forTenant($tenantId)
            ->where('entity_kind', 'campaign')
            ->with(['fund', 'assignments.family'])
            ->findOrFail($id);
    }
}
