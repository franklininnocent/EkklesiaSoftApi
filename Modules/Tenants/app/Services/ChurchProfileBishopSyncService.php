<?php

namespace Modules\Tenants\Services;

use Modules\EcclesiasticalData\Services\DioceseLeadershipQueryService;
use Modules\Tenants\Models\ChurchProfile;

/**
 * Keeps church_profiles.bishop_id aligned with canonical diocesan leadership.
 */
class ChurchProfileBishopSyncService
{
    public function __construct(
        private readonly DioceseLeadershipQueryService $leadershipQuery,
    ) {}

    /**
     * After ordinary succession, align all parish profiles in the diocese.
     */
    public function syncAfterSuccession(int $dioceseId, ?int $endedBishopId, int $newBishopId): void
    {
        $this->syncDioceseProfilesToCurrentOrdinary($dioceseId, $newBishopId);
    }

    /**
     * Bulk-align every church profile in a diocese to the resolved current ordinary.
     */
    public function syncDioceseProfilesToCurrentOrdinary(int $dioceseId, ?int $bishopId = null): int
    {
        if ($bishopId === null) {
            $leadership = $this->leadershipQuery->getCurrentLeadership($dioceseId);
            $bishopId = $leadership['ordinary']['bishop_id'] ?? null;
        }

        $query = ChurchProfile::query()->where('archdiocese_id', $dioceseId);

        if ($bishopId === null) {
            return $query->update(['bishop_id' => null]);
        }

        return $query->update(['bishop_id' => $bishopId]);
    }
}
