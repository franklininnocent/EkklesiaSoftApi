<?php

namespace Modules\BCC\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BCCLeader;
use Modules\BCC\Repositories\BCCRepository;

class BCCService
{
    /**
     * @var BCCRepository
     */
    protected BCCRepository $bccRepository;

    /**
     * BCCService constructor.
     *
     * @param BCCRepository $bccRepository
     */
    public function __construct(BCCRepository $bccRepository)
    {
        $this->bccRepository = $bccRepository;
    }

    /**
     * Get paginated BCCs
     *
     * @param string $tenantId
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginatedBCCs(string $tenantId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->bccRepository->getPaginatedBCCs($tenantId, $filters, $perPage);
    }

    /**
     * Get all BCCs for tenant
     *
     * @param string $tenantId
     * @return Collection
     */
    public function getAllBCCs(string $tenantId): Collection
    {
        return $this->bccRepository->getAllBCCs($tenantId);
    }

    /**
     * Get BCC by ID
     *
     * @param string $id
     * @param string $tenantId
     * @return BCC|null
     */
    public function getBCCById(string $id, string $tenantId): ?BCC
    {
        return $this->bccRepository->findById($id, $tenantId);
    }

    /**
     * Create a new BCC
     *
     * @param array $data
     * @param string $tenantId
     * @param string $userId
     * @return BCC
     * @throws \Exception
     */
    public function createBCC(array $data, string $tenantId, string $userId): BCC
    {
        try {
            DB::beginTransaction();

            // Add tenant and audit info
            $data['tenant_id'] = $tenantId;
            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;

            // Create BCC
            $bcc = $this->bccRepository->create($data);

            // If leader data is provided, add leaders
            if (!empty($data['leaders']) && is_array($data['leaders'])) {
                foreach ($data['leaders'] as $leaderData) {
                    $leaderData['created_by'] = $userId;
                    $leaderData['updated_by'] = $userId;
                    $leaderData['is_current'] = true;
                    $leaderData['assigned_date'] = $leaderData['assigned_date'] ?? now()->toDateString();
                    $this->bccRepository->addLeader($bcc->id, $leaderData);
                }
            }

            DB::commit();

            Log::info('BCC created', [
                'bcc_id' => $bcc->id,
                'bcc_code' => $bcc->bcc_code,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            // Reload with relationships
            return $this->bccRepository->findById($bcc->id, $tenantId);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create BCC', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    /**
     * Update BCC
     *
     * @param string $id
     * @param array $data
     * @param string $tenantId
     * @param string $userId
     * @return BCC|null
     * @throws \Exception
     */
    public function updateBCC(string $id, array $data, string $tenantId, string $userId): ?BCC
    {
        try {
            $bcc = $this->bccRepository->findById($id, $tenantId);

            if (!$bcc) {
                return null;
            }

            DB::beginTransaction();

            // Add audit info
            $data['updated_by'] = $userId;

            // Update BCC
            $this->bccRepository->update($bcc, $data);

            DB::commit();

            Log::info('BCC updated', [
                'bcc_id' => $bcc->id,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            // Reload with relationships
            return $this->bccRepository->findById($id, $tenantId);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update BCC', [
                'bcc_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    /**
     * Delete BCC
     *
     * @param string $id
     * @param string $tenantId
     * @param string $userId
     * @return bool
     * @throws \Exception
     */
    public function deleteBCC(string $id, string $tenantId, string $userId): bool
    {
        try {
            $bcc = $this->bccRepository->findById($id, $tenantId);

            if (!$bcc) {
                return false;
            }

            DB::beginTransaction();

            // Unassign families from BCC before deletion
            if ($bcc->families->isNotEmpty()) {
                $familyIds = $bcc->families->pluck('id')->toArray();
                $this->bccRepository->removeFamilies($familyIds);
            }

            // Delete BCC (this will cascade to leaders)
            $result = $this->bccRepository->delete($bcc);

            DB::commit();

            Log::info('BCC deleted', [
                'bcc_id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete BCC', [
                'bcc_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
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
        return $this->bccRepository->getBCCsByParishZone($parishZoneId, $tenantId);
    }

    /**
     * Get BCCs with available space
     *
     * @param string $tenantId
     * @return Collection
     */
    public function getBCCsWithSpace(string $tenantId): Collection
    {
        return $this->bccRepository->getBCCsWithSpace($tenantId);
    }

    /**
     * Get BCC statistics
     *
     * @param string $tenantId
     * @return array
     */
    public function getStatistics(string $tenantId): array
    {
        return $this->bccRepository->getStatistics($tenantId);
    }

    // ==================== LEADER OPERATIONS ====================

    /**
     * Get all leaders for a BCC
     *
     * @param string $bccId
     * @param string $tenantId
     * @return Collection|null
     */
    public function getLeaders(string $bccId, string $tenantId): ?Collection
    {
        // Verify BCC belongs to tenant
        $bcc = $this->bccRepository->findById($bccId, $tenantId);
        if (!$bcc) {
            return null;
        }

        return $this->bccRepository->getLeaders($bccId);
    }

    /**
     * Add leader to BCC
     *
     * @param string $bccId
     * @param array $leaderData
     * @param string $tenantId
     * @param string $userId
     * @return BCCLeader|null
     * @throws \Exception
     */
    public function addLeader(string $bccId, array $leaderData, string $tenantId, string $userId): ?BCCLeader
    {
        try {
            // Verify BCC belongs to tenant
            $bcc = $this->bccRepository->findById($bccId, $tenantId);
            if (!$bcc) {
                return null;
            }

            DB::beginTransaction();

            // Add audit info
            $leaderData['created_by'] = $userId;
            $leaderData['updated_by'] = $userId;
            $leaderData['is_current'] = $leaderData['is_current'] ?? true;
            $leaderData['assigned_date'] = $leaderData['assigned_date'] ?? now()->toDateString();

            // Create leader
            $leader = $this->bccRepository->addLeader($bccId, $leaderData);

            DB::commit();

            Log::info('BCC leader added', [
                'leader_id' => $leader->id,
                'bcc_id' => $bccId,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            return $leader->fresh(['user', 'familyMember']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to add BCC leader', [
                'bcc_id' => $bccId,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    /**
     * Update BCC leader
     *
     * @param string $bccId
     * @param string $leaderId
     * @param array $data
     * @param string $tenantId
     * @param string $userId
     * @return BCCLeader|null
     * @throws \Exception
     */
    public function updateLeader(string $bccId, string $leaderId, array $data, string $tenantId, string $userId): ?BCCLeader
    {
        try {
            // Verify BCC belongs to tenant
            $bcc = $this->bccRepository->findById($bccId, $tenantId);
            if (!$bcc) {
                return null;
            }

            $leader = $this->bccRepository->findLeaderById($leaderId, $bccId);
            if (!$leader) {
                return null;
            }

            DB::beginTransaction();

            // Add audit info
            $data['updated_by'] = $userId;

            // Update leader
            $this->bccRepository->updateLeader($leader, $data);

            DB::commit();

            Log::info('BCC leader updated', [
                'leader_id' => $leaderId,
                'bcc_id' => $bccId,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            return $leader->fresh(['user', 'familyMember']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update BCC leader', [
                'leader_id' => $leaderId,
                'bcc_id' => $bccId,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    /**
     * Delete BCC leader
     *
     * @param string $bccId
     * @param string $leaderId
     * @param string $tenantId
     * @param string $userId
     * @return bool
     * @throws \Exception
     */
    public function deleteLeader(string $bccId, string $leaderId, string $tenantId, string $userId): bool
    {
        try {
            // Verify BCC belongs to tenant
            $bcc = $this->bccRepository->findById($bccId, $tenantId);
            if (!$bcc) {
                return false;
            }

            $leader = $this->bccRepository->findLeaderById($leaderId, $bccId);
            if (!$leader) {
                return false;
            }

            DB::beginTransaction();

            $result = $this->bccRepository->deleteLeader($leader);

            DB::commit();

            Log::info('BCC leader deleted', [
                'leader_id' => $leaderId,
                'bcc_id' => $bccId,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete BCC leader', [
                'leader_id' => $leaderId,
                'bcc_id' => $bccId,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    // ==================== FAMILY ASSIGNMENT ====================

    /**
     * Assign families to BCC
     *
     * @param string $bccId
     * @param array $familyIds
     * @param string $tenantId
     * @param string $userId
     * @return array
     * @throws \Exception
     */
    public function assignFamilies(string $bccId, array $familyIds, string $tenantId, string $userId): array
    {
        try {
            $bcc = $this->bccRepository->findById($bccId, $tenantId);
            if (!$bcc) {
                return ['success' => false, 'message' => 'BCC not found'];
            }

            // Check capacity
            if (!$this->bccRepository->canAcceptFamilies($bcc, count($familyIds))) {
                return [
                    'success' => false,
                    'message' => 'BCC does not have enough capacity for the requested families',
                    'current_count' => $bcc->current_family_count,
                    'max_families' => $bcc->max_families,
                    'available_space' => $bcc->max_families - $bcc->current_family_count
                ];
            }

            DB::beginTransaction();

            $assignedCount = $this->bccRepository->assignFamilies($bccId, $familyIds);

            DB::commit();

            Log::info('Families assigned to BCC', [
                'bcc_id' => $bccId,
                'family_count' => $assignedCount,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            return [
                'success' => true,
                'message' => "{$assignedCount} families assigned successfully",
                'assigned_count' => $assignedCount
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to assign families to BCC', [
                'bcc_id' => $bccId,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    /**
     * Remove families from BCC
     *
     * @param array $familyIds
     * @param string $tenantId
     * @param string $userId
     * @return int
     * @throws \Exception
     */
    public function removeFamilies(array $familyIds, string $tenantId, string $userId): int
    {
        try {
            DB::beginTransaction();

            $removedCount = $this->bccRepository->removeFamilies($familyIds);

            DB::commit();

            Log::info('Families removed from BCC', [
                'family_count' => $removedCount,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            return $removedCount;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to remove families from BCC', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }
}


