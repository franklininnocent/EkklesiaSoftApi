<?php

namespace Modules\Family\app\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\app\Repositories\FamilyRepository;
use Modules\Family\app\Services\FamilyFileUploadService;
use Carbon\Carbon;

class FamilyService
{
    /**
     * @var FamilyRepository
     */
    protected FamilyRepository $familyRepository;

    /**
     * @var FamilyFileUploadService
     */
    protected FamilyFileUploadService $fileUploadService;

    /**
     * FamilyService constructor.
     *
     * @param FamilyRepository $familyRepository
     * @param FamilyFileUploadService $fileUploadService
     */
    public function __construct(FamilyRepository $familyRepository, FamilyFileUploadService $fileUploadService)
    {
        $this->familyRepository = $familyRepository;
        $this->fileUploadService = $fileUploadService;
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
            // Sanitize UUIDs - remove any whitespace or extra characters
            $familyId = trim($familyId);
            $memberId = trim($memberId);
            
            // Extract UUID pattern (36 characters with hyphens)
            if (preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $familyId, $matches)) {
                $familyId = $matches[1];
            }
            if (preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $memberId, $matches)) {
                $memberId = $matches[1];
            }
            
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

            // Log the data being sent for debugging
            Log::debug('Updating family member', [
                'member_id' => $memberId,
                'family_id' => $familyId,
                'data' => $data,
                'member_current_data' => $member->toArray()
            ]);

            // Add audit info
            $data['updated_by'] = $userId;

            // Update member
            $updated = $this->familyRepository->updateMember($member, $data);
            
            if (!$updated) {
                DB::rollBack();
                Log::error('Failed to update family member - update returned false', [
                    'member_id' => $memberId,
                    'family_id' => $familyId,
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'data' => $data
                ]);
                throw new \RuntimeException('Failed to update family member');
            }

            DB::commit();

            Log::info('Family member updated', [
                'member_id' => $memberId,
                'family_id' => $familyId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'updated_fields' => array_keys($data)
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

    /**
     * Upload profile image for family head
     *
     * @param string $id
     * @param \Illuminate\Http\UploadedFile $file
     * @param string $tenantId
     * @param string $userId
     * @return Family|null
     * @throws \Exception
     */
    public function uploadProfileImage(string $id, $file, string $tenantId, string $userId): ?Family
    {
        try {
            $family = $this->familyRepository->findById($id, $tenantId);

            if (!$family) {
                return null;
            }

            DB::beginTransaction();

            // Upload the new image
            $imagePath = $this->fileUploadService->uploadProfileImage(
                $file,
                $tenantId,
                $family->profile_image_url
            );

            if (!$imagePath) {
                DB::rollBack();
                throw new \Exception('Failed to upload profile image');
            }

            // Update family with new image path
            $this->familyRepository->update($family, [
                'profile_image_url' => $imagePath,
                'updated_by' => $userId
            ]);

            DB::commit();

            Log::info('Family profile image uploaded', [
                'family_id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'image_path' => $imagePath
            ]);

            return $this->familyRepository->findById($id, $tenantId);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to upload family profile image', [
                'family_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    /**
     * Delete profile image for family
     *
     * @param string $id
     * @param string $tenantId
     * @param string $userId
     * @return Family|null
     * @throws \Exception
     */
    public function deleteProfileImage(string $id, string $tenantId, string $userId): ?Family
    {
        try {
            $family = $this->familyRepository->findById($id, $tenantId);

            if (!$family) {
                return null;
            }

            if (!$family->profile_image_url) {
                // No image to delete
                return $family;
            }

            DB::beginTransaction();

            // Delete the image file
            $this->fileUploadService->deleteProfileImage($family->profile_image_url, $tenantId);

            // Update family to remove image path
            $this->familyRepository->update($family, [
                'profile_image_url' => null,
                'updated_by' => $userId
            ]);

            DB::commit();

            Log::info('Family profile image deleted', [
                'family_id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            return $this->familyRepository->findById($id, $tenantId);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete family profile image', [
                'family_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    /**
     * Upload profile image for family head
     *
     * @param string $id
     * @param mixed $file
     * @param string $tenantId
     * @param string $userId
     * @return Family|null
     * @throws \Exception
     */
    public function uploadHeadProfileImage(string $id, $file, string $tenantId, string $userId): ?Family
    {
        try {
            $family = $this->familyRepository->findById($id, $tenantId);

            if (!$family) {
                return null;
            }

            DB::beginTransaction();

            // Upload the new image
            $imagePath = $this->fileUploadService->uploadProfileImage(
                $file,
                $tenantId,
                $family->head_profile_image_url
            );

            if (!$imagePath) {
                DB::rollBack();
                throw new \Exception('Failed to upload head profile image');
            }

            // Update family with new image path
            $this->familyRepository->update($family, [
                'head_profile_image_url' => $imagePath,
                'updated_by' => $userId
            ]);

            DB::commit();

            Log::info('Family head profile image uploaded', [
                'family_id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'image_path' => $imagePath
            ]);

            return $this->familyRepository->findById($id, $tenantId);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to upload family head profile image', [
                'family_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    /**
     * Delete profile image for family head
     *
     * @param string $id
     * @param string $tenantId
     * @param string $userId
     * @return Family|null
     * @throws \Exception
     */
    public function deleteHeadProfileImage(string $id, string $tenantId, string $userId): ?Family
    {
        try {
            $family = $this->familyRepository->findById($id, $tenantId);

            if (!$family) {
                return null;
            }

            if (!$family->head_profile_image_url) {
                // No image to delete
                return $family;
            }

            DB::beginTransaction();

            // Delete the image file
            $this->fileUploadService->deleteProfileImage($family->head_profile_image_url, $tenantId);

            // Update family to remove image path
            $this->familyRepository->update($family, [
                'head_profile_image_url' => null,
                'updated_by' => $userId
            ]);

            DB::commit();

            Log::info('Family head profile image deleted', [
                'family_id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            return $this->familyRepository->findById($id, $tenantId);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete family head profile image', [
                'family_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }
}


