<?php

namespace Modules\Family\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Repositories\FamilyRepository;
use Carbon\Carbon;

class FamilyService
{
    /**
     * @var FamilyRepository
     */
    protected FamilyRepository $familyRepository;

    /**
     * FamilyService constructor.
     *
     * @param FamilyRepository $familyRepository
     */
    public function __construct(FamilyRepository $familyRepository)
    {
        $this->familyRepository = $familyRepository;
    }

    /**
     * Get paginated families
     *
     * @param string $tenantId
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginatedFamilies(string $tenantId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->familyRepository->getPaginatedFamilies($tenantId, $filters, $perPage);
    }

    /**
     * Get all families for tenant
     *
     * @param string $tenantId
     * @return Collection
     */
    public function getAllFamilies(string $tenantId): Collection
    {
        return $this->familyRepository->getAllFamilies($tenantId);
    }

    /**
     * Get family by ID
     *
     * @param string $id
     * @param string $tenantId
     * @return Family|null
     */
    public function getFamilyById(string $id, string $tenantId): ?Family
    {
        return $this->familyRepository->findById($id, $tenantId);
    }

    /**
     * Create a new family
     *
     * @param array $data
     * @param string $tenantId
     * @param string $userId
     * @return Family
     * @throws \Exception
     */
    public function createFamily(array $data, string $tenantId, string $userId): Family
    {
        try {
            DB::beginTransaction();

            // Add tenant and audit info
            $data['tenant_id'] = $tenantId;
            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;

            // Create family
            $family = $this->familyRepository->create($data);

            // If members data is provided, create them
            if (!empty($data['members']) && is_array($data['members'])) {
                foreach ($data['members'] as $memberData) {
                    $memberData['created_by'] = $userId;
                    $memberData['updated_by'] = $userId;
                    $this->familyRepository->addMember($family, $memberData);
                }
            }

            DB::commit();

            Log::info('Family created', [
                'family_id' => $family->id,
                'family_code' => $family->family_code,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            // Reload with relationships
            return $this->familyRepository->findById($family->id, $tenantId);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create family', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    /**
     * Update family
     *
     * @param string $id
     * @param array $data
     * @param string $tenantId
     * @param string $userId
     * @return Family|null
     * @throws \Exception
     */
    public function updateFamily(string $id, array $data, string $tenantId, string $userId): ?Family
    {
        try {
            $family = $this->familyRepository->findById($id, $tenantId);

            if (!$family) {
                return null;
            }

            DB::beginTransaction();

            // Optimistic locking: if client sent updated_at, ensure it matches current
            if (!empty($data['updated_at'])) {
                $clientUpdatedAt = Carbon::parse($data['updated_at']);
                if (!$family->updated_at || !$family->updated_at->equalTo($clientUpdatedAt)) {
                    DB::rollBack();
                    throw new \RuntimeException('Conflict: record has been modified by another process.', 409);
                }
            }
            unset($data['updated_at']);

            // Add audit info
            $data['updated_by'] = $userId;

            // Update family
            $this->familyRepository->update($family, $data);

            DB::commit();

            Log::info('Family updated', [
                'family_id' => $family->id,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            // Reload with relationships
            return $this->familyRepository->findById($id, $tenantId);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update family', [
                'family_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    /**
     * Delete family
     *
     * @param string $id
     * @param string $tenantId
     * @param string $userId
     * @return bool
     * @throws \Exception
     */
    public function deleteFamily(string $id, string $tenantId, string $userId): bool
    {
        try {
            $family = $this->familyRepository->findById($id, $tenantId);

            if (!$family) {
                return false;
            }

            DB::beginTransaction();

            // Delete family (this will cascade to members)
            $result = $this->familyRepository->delete($family);

            DB::commit();

            Log::info('Family deleted', [
                'family_id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete family', [
                'family_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    /**
     * Get families by BCC
     *
     * @param string $bccId
     * @param string $tenantId
     * @return Collection
     */
    public function getFamiliesByBCC(string $bccId, string $tenantId): Collection
    {
        return $this->familyRepository->getFamiliesByBCC($bccId, $tenantId);
    }

    /**
     * Get families by parish zone
     *
     * @param string $parishZoneId
     * @param string $tenantId
     * @return Collection
     */
    public function getFamiliesByParishZone(string $parishZoneId, string $tenantId): Collection
    {
        return $this->familyRepository->getFamiliesByParishZone($parishZoneId, $tenantId);
    }

    /**
     * Get families without BCC assignment
     *
     * @param string $tenantId
     * @return Collection
     */
    public function getFamiliesWithoutBCC(string $tenantId): Collection
    {
        return $this->familyRepository->getFamiliesWithoutBCC($tenantId);
    }

    /**
     * Get family statistics
     *
     * @param string $tenantId
     * @return array
     */
    public function getStatistics(string $tenantId): array
    {
        return $this->familyRepository->getStatistics($tenantId);
    }

    /**
     * Add member to family
     *
     * @param string $familyId
     * @param array $memberData
     * @param string $tenantId
     * @param string $userId
     * @return FamilyMember|null
     * @throws \Exception
     */
    public function addMember(string $familyId, array $memberData, string $tenantId, string $userId): ?FamilyMember
    {
        try {
            $family = $this->familyRepository->findById($familyId, $tenantId);

            if (!$family) {
                return null;
            }

            DB::beginTransaction();

            // Add audit info
            $memberData['created_by'] = $userId;
            $memberData['updated_by'] = $userId;

            // Create member
            $member = $this->familyRepository->addMember($family, $memberData);

            DB::commit();

            Log::info('Family member added', [
                'member_id' => $member->id,
                'family_id' => $familyId,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            return $member;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to add family member', [
                'family_id' => $familyId,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    /**
     * Update family member
     *
     * @param string $familyId
     * @param string $memberId
     * @param array $data
     * @param string $tenantId
     * @param string $userId
     * @return FamilyMember|null
     * @throws \Exception
     */
    public function updateMember(string $familyId, string $memberId, array $data, string $tenantId, string $userId): ?FamilyMember
    {
        try {
            // Verify family belongs to tenant
            $family = $this->familyRepository->findById($familyId, $tenantId);
            if (!$family) {
                return null;
            }

            $member = $this->familyRepository->findMemberById($memberId, $familyId);
            if (!$member) {
                return null;
            }

            DB::beginTransaction();

            // Add audit info
            $data['updated_by'] = $userId;

            // Update member
            $this->familyRepository->updateMember($member, $data);

            DB::commit();

            Log::info('Family member updated', [
                'member_id' => $memberId,
                'family_id' => $familyId,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            return $member->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update family member', [
                'member_id' => $memberId,
                'family_id' => $familyId,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    /**
     * Delete family member
     *
     * @param string $familyId
     * @param string $memberId
     * @param string $tenantId
     * @param string $userId
     * @return bool
     * @throws \Exception
     */
    public function deleteMember(string $familyId, string $memberId, string $tenantId, string $userId): bool
    {
        try {
            // Verify family belongs to tenant
            $family = $this->familyRepository->findById($familyId, $tenantId);
            if (!$family) {
                return false;
            }

            $member = $this->familyRepository->findMemberById($memberId, $familyId);
            if (!$member) {
                return false;
            }

            DB::beginTransaction();

            $result = $this->familyRepository->deleteMember($member);

            DB::commit();

            Log::info('Family member deleted', [
                'member_id' => $memberId,
                'family_id' => $familyId,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete family member', [
                'member_id' => $memberId,
                'family_id' => $familyId,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    /**
     * Get all members of a family
     *
     * @param string $familyId
     * @param string $tenantId
     * @return Collection|null
     */
    public function getFamilyMembers(string $familyId, string $tenantId): ?Collection
    {
        // Verify family belongs to tenant
        $family = $this->familyRepository->findById($familyId, $tenantId);
        if (!$family) {
            return null;
        }

        return $this->familyRepository->getFamilyMembers($familyId);
    }
}


