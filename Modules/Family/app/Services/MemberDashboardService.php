<?php

namespace Modules\Family\app\Services;

use Modules\Family\app\Repositories\FamilyRepository;

class MemberDashboardService
{
    public function __construct(
        private readonly FamilyRepository $familyRepository,
        private readonly MemberAgeDemographicsService $ageDemographicsService,
        private readonly MemberCelebrationsService $memberCelebrationsService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(int $tenantId): array
    {
        $statistics = $this->familyRepository->getStatistics((string) $tenantId);
        $demographics = $this->ageDemographicsService->forTenant($tenantId);
        $celebrations = $this->memberCelebrationsService->weekCelebrations($tenantId);

        return [
            'statistics' => $statistics,
            'demographics' => $demographics,
            'celebrations' => [
                'week_label' => $celebrations['week']['label'] ?? '',
                'week_start' => $celebrations['week']['start'] ?? '',
                'week_end' => $celebrations['week']['end'] ?? '',
                'birthdays_count' => count($celebrations['birthdays'] ?? []),
                'anniversaries_count' => count($celebrations['anniversaries'] ?? []),
            ],
        ];
    }
}
