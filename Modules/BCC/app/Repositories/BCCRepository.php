<?php

namespace Modules\BCC\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BCCLeader;
use Modules\Family\Models\Family;

class BCCRepository
{
    /**
     * Get paginated BCCs for a tenant with optional filters
     *
     * @param string $tenantId
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginatedBCCs(string $tenantId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = BCC::where('tenant_id', $tenantId)
            ->with(['leaders.familyMember', 'leaders.user'])
            ->withCount('families');

        // Apply filters
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('bcc_code', 'ILIKE', "%{$search}%")
                    ->orWhere('contact_phone', 'ILIKE', "%{$search}%")
                    ->orWhere('contact_email', 'ILIKE', "%{$search}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Parish Zone removed

        if (isset($filters['has_space']) && $filters['has_space']) {
            $query->hasSpace();
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($perPage);
    }

    /**
     * Get all BCCs for a tenant
     *
     * @param string $tenantId
     * @return Collection
     */
    public function getAllBCCs(string $tenantId): Collection
    {
        return BCC::where('tenant_id', $tenantId)
            ->with(['leaders'])
            ->withCount('families')
            ->orderBy('name')
            ->get();
    }

    /**
     * Find BCC by ID
     *
     * @param string $id
     * @param string $tenantId
     * @return BCC|null
     */
    public function findById(string $id, string $tenantId): ?BCC
    {
        return BCC::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with([
                'families' => function ($query) {
                    $query->orderBy('family_name');
                },
                'leaders' => function ($query) {
                    $query->where('is_current', true);
                },
                'leaders.familyMember',
                'leaders.user',
                'creator:id,name',
                'updater:id,name'
            ])
            ->first();
    }

    /**
     * Find BCC by code
     *
     * @param string $bccCode
     * @param string $tenantId
     * @return BCC|null
     */
    public function findByCode(string $bccCode, string $tenantId): ?BCC
    {
        return BCC::where('bcc_code', $bccCode)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * Create a new BCC
     *
     * @param array $data
     * @return BCC
     */
    public function create(array $data): BCC
    {
        return BCC::create($data);
    }

    /**
     * Update BCC
     *
     * @param BCC $bcc
     * @param array $data
     * @return bool
     */
    public function update(BCC $bcc, array $data): bool
    {
        return $bcc->update($data);
    }

    /**
     * Delete BCC (soft delete)
     *
     * @param BCC $bcc
     * @return bool|null
     */
    public function delete(BCC $bcc): ?bool
    {
        return $bcc->delete();
    }

    /**
     * Get BCCs by parish zone
     *
     * @param string $parishZoneId
     * @param string $tenantId
     * @return Collection
     */
    public function getBCCsByParishZone(string $parishZoneId, string $tenantId): Collection
    {
        return BCC::where('tenant_id', $tenantId)
            ->withCount('families')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get BCCs with available space
     *
     * @param string $tenantId
     * @return Collection
     */
    public function getBCCsWithSpace(string $tenantId): Collection
    {
        return BCC::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->hasSpace()
            ->withCount('families')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get BCC statistics for tenant
     *
     * @param string $tenantId
     * @return array
     */
    public function getStatistics(string $tenantId): array
    {
        $totalBCCs = BCC::where('tenant_id', $tenantId)->count();
        $activeBCCs = BCC::where('tenant_id', $tenantId)->where('status', 'active')->count();
        
        $bccsWithSpace = BCC::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->hasSpace()
            ->count();

        $totalFamiliesInBCC = Family::where('tenant_id', $tenantId)
            ->whereNotNull('bcc_id')
            ->count();

        // Parish Zone removed; keep empty for compatibility
        $bccsByZone = collect();

        $capacityUtilization = BCC::where('tenant_id', $tenantId)
            ->selectRaw('
                SUM(max_families) as total_capacity,
                (SELECT COUNT(*) FROM families WHERE families.bcc_id = bccs.id) as current_count
            ')
            ->first();

        return [
            'total_bccs' => $totalBCCs,
            'active_bccs' => $activeBCCs,
            'inactive_bccs' => $totalBCCs - $activeBCCs,
            'bccs_with_space' => $bccsWithSpace,
            'total_families_in_bcc' => $totalFamiliesInBCC,
            'bccs_by_zone' => $bccsByZone,
            'total_capacity' => $capacityUtilization->total_capacity ?? 0,
            'current_utilization' => $totalFamiliesInBCC,
            'utilization_percentage' => $capacityUtilization && $capacityUtilization->total_capacity > 0
                ? round(($totalFamiliesInBCC / $capacityUtilization->total_capacity) * 100, 2)
                : 0,
        ];
    }

    // ==================== LEADER OPERATIONS ====================

    /**
     * Get all leaders for a BCC
     *
     * @param string $bccId
     * @return Collection
     */
    public function getLeaders(string $bccId): Collection
    {
        return BCCLeader::where('bcc_id', $bccId)
            ->with(['user', 'familyMember', 'creator', 'updater'])
            ->orderBy('is_current', 'desc')
            ->orderBy('assigned_date', 'desc')
            ->get();
    }

    /**
     * Get current leaders for a BCC
     *
     * @param string $bccId
     * @return Collection
     */
    public function getCurrentLeaders(string $bccId): Collection
    {
        return BCCLeader::where('bcc_id', $bccId)
            ->where('is_current', true)
            ->with(['user', 'familyMember'])
            ->orderBy('role')
            ->get();
    }

    /**
     * Find leader by ID
     *
     * @param string $leaderId
     * @param string $bccId
     * @return BCCLeader|null
     */
    public function findLeaderById(string $leaderId, string $bccId): ?BCCLeader
    {
        return BCCLeader::where('id', $leaderId)
            ->where('bcc_id', $bccId)
            ->first();
    }

    /**
     * Add leader to BCC
     *
     * @param string $bccId
     * @param array $leaderData
     * @return BCCLeader
     */
    public function addLeader(string $bccId, array $leaderData): BCCLeader
    {
        $leaderData['bcc_id'] = $bccId;
        return BCCLeader::create($leaderData);
    }

    /**
     * Update leader
     *
     * @param BCCLeader $leader
     * @param array $data
     * @return bool
     */
    public function updateLeader(BCCLeader $leader, array $data): bool
    {
        return $leader->update($data);
    }

    /**
     * Delete leader
     *
     * @param BCCLeader $leader
     * @return bool|null
     */
    public function deleteLeader(BCCLeader $leader): ?bool
    {
        return $leader->delete();
    }

    /**
     * Mark leader as inactive
     *
     * @param BCCLeader $leader
     * @param string|null $endDate
     * @return bool
     */
    public function deactivateLeader(BCCLeader $leader, ?string $endDate = null): bool
    {
        return $leader->update([
            'is_current' => false,
            'end_date' => $endDate ?? now()->toDateString(),
        ]);
    }

    // ==================== FAMILY ASSIGNMENT ====================

    /**
     * Assign families to BCC
     *
     * @param string $bccId
     * @param array $familyIds
     * @return int Number of families assigned
     */
    public function assignFamilies(string $bccId, array $familyIds): int
    {
        return Family::whereIn('id', $familyIds)
            ->update(['bcc_id' => $bccId]);
    }

    /**
     * Remove families from BCC
     *
     * @param array $familyIds
     * @return int Number of families removed
     */
    public function removeFamilies(array $familyIds): int
    {
        return Family::whereIn('id', $familyIds)
            ->update(['bcc_id' => null]);
    }

    /**
     * Check if BCC can accept more families
     *
     * @param BCC $bcc
     * @param int $count
     * @return bool
     */
    public function canAcceptFamilies(BCC $bcc, int $count = 1): bool
    {
        $currentCount = $bcc->current_family_count;
        return ($currentCount + $count) <= $bcc->max_families;
    }
}


