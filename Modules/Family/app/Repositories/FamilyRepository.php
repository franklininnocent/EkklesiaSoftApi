<?php

namespace Modules\Family\app\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;

class FamilyRepository
{
    /**
     * Get paginated families for a tenant with optional filters
     *
     * @param string $tenantId
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginatedFamilies(string $tenantId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Family::where('tenant_id', $tenantId)
            ->with(['bcc:id,name', 'members']);

        // Apply filters
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('family_name', 'ILIKE', "%{$search}%")
                    ->orWhere('family_code', 'ILIKE', "%{$search}%")
                    ->orWhere('head_of_family', 'ILIKE', "%{$search}%")
                    ->orWhere('primary_phone', 'ILIKE', "%{$search}%")
                    ->orWhere('email', 'ILIKE', "%{$search}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['bcc_id'])) {
            $query->where('bcc_id', $filters['bcc_id']);
        }

        // Parish Zone removed

        if (!empty($filters['city'])) {
            $query->where('city', 'ILIKE', "%{$filters['city']}%");
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($perPage);
    }

    /**
     * Get all families for a tenant
     *
     * @param string $tenantId
     * @return Collection
     */
    public function getAllFamilies(string $tenantId): Collection
    {
        return Family::where('tenant_id', $tenantId)
            ->with(['bcc', 'members'])
            ->orderBy('family_name')
            ->get();
    }

    /**
     * Find family by ID
     *
     * @param string $id
     * @param string $tenantId
     * @return Family|null
     */
    public function findById(string $id, string $tenantId): ?Family
    {
        return Family::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with([
                'bcc',
                'country',
                'state',
                'members' => function ($query) {
                    $query->orderBy('relationship_to_head');
                },
                'creator:id,name',
                'updater:id,name'
            ])
            ->first();
    }

    /**
     * Find family by family code
     *
     * @param string $familyCode
     * @param string $tenantId
     * @return Family|null
     */
    public function findByFamilyCode(string $familyCode, string $tenantId): ?Family
    {
        return Family::where('family_code', $familyCode)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * Create a new family
     *
     * @param array $data
     * @return Family
     */
    public function create(array $data): Family
    {
        return Family::create($data);
    }

    /**
     * Update family
     *
     * @param Family $family
     * @param array $data
     * @return bool
     */
    public function update(Family $family, array $data): bool
    {
        return $family->update($data);
    }

    /**
     * Delete family (soft delete)
     *
     * @param Family $family
     * @return bool|null
     */
    public function delete(Family $family): ?bool
    {
        return $family->delete();
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
        return Family::where('tenant_id', $tenantId)
            ->where('bcc_id', $bccId)
            ->with(['members'])
            ->orderBy('family_name')
            ->get();
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
        return Family::where('tenant_id', $tenantId)
            ->where('parish_zone_id', $parishZoneId)
            ->with(['members'])
            ->orderBy('family_name')
            ->get();
    }

    /**
     * Get families without BCC
     *
     * @param string $tenantId
     * @return Collection
     */
    public function getFamiliesWithoutBCC(string $tenantId): Collection
    {
        return Family::where('tenant_id', $tenantId)
            ->whereNull('bcc_id')
            ->orderBy('family_name')
            ->get();
    }

    /**
     * Get family statistics for tenant
     *
     * @param string $tenantId
     * @return array
     */
    public function getStatistics(string $tenantId): array
    {
        $totalFamilies = Family::where('tenant_id', $tenantId)->count();
        $activeFamilies = Family::where('tenant_id', $tenantId)->where('status', 'active')->count();
        $totalMembers = FamilyMember::whereHas('family', function ($q) use ($tenantId) {
            $q->where('tenant_id', $tenantId);
        })->count();
        $activeMembers = FamilyMember::whereHas('family', function ($q) use ($tenantId) {
            $q->where('tenant_id', $tenantId);
        })->where('status', 'active')->count();

        $familiesWithBCC = Family::where('tenant_id', $tenantId)->whereNotNull('bcc_id')->count();
        $familiesWithoutBCC = Family::where('tenant_id', $tenantId)->whereNull('bcc_id')->count();

        // Parish Zone removed; keep key for compatibility but empty list
        $familiesByZone = collect();

        return [
            'total_families' => $totalFamilies,
            'active_families' => $activeFamilies,
            'inactive_families' => $totalFamilies - $activeFamilies,
            'total_members' => $totalMembers,
            'active_members' => $activeMembers,
            'families_with_bcc' => $familiesWithBCC,
            'families_without_bcc' => $familiesWithoutBCC,
            'families_by_zone' => $familiesByZone,
        ];
    }

    /**
     * Add member to family
     *
     * @param Family $family
     * @param array $memberData
     * @return FamilyMember
     */
    public function addMember(Family $family, array $memberData): FamilyMember
    {
        $memberData['family_id'] = $family->id;
        $member = FamilyMember::create($memberData);
        
        // Auto-sync head_of_family if this member is designated as head
        if (isset($memberData['relationship_to_head']) && in_array(strtolower($memberData['relationship_to_head']), ['self', 'head'])) {
            $this->syncHeadOfFamily($family->id);
        }
        
        return $member;
    }

    /**
     * Update family member
     *
     * @param FamilyMember $member
     * @param array $data
     * @return bool
     */
    public function updateMember(FamilyMember $member, array $data): bool
    {
        $updated = $member->update($data);
        
        if ($updated) {
            // Refresh the model to ensure we have the latest data
            $member->refresh();
            
            // Auto-sync head_of_family if relationship_to_head was changed
            if (isset($data['relationship_to_head']) && in_array(strtolower($data['relationship_to_head']), ['self', 'head'])) {
                $this->syncHeadOfFamily($member->family_id);
            }
        }
        
        return $updated;
    }

    /**
     * Delete family member
     *
     * @param FamilyMember $member
     * @return bool|null
     */
    public function deleteMember(FamilyMember $member): ?bool
    {
        return $member->delete();
    }

    /**
     * Get member by ID
     *
     * @param string $memberId
     * @param string $familyId
     * @return FamilyMember|null
     */
    public function findMemberById(string $memberId, string $familyId): ?FamilyMember
    {
        return FamilyMember::where('id', $memberId)
            ->where('family_id', $familyId)
            ->first();
    }

    /**
     * Get all members of a family
     *
     * @param string $familyId
     * @return Collection
     */
    public function getFamilyMembers(string $familyId): Collection
    {
        return FamilyMember::where('family_id', $familyId)
            ->orderBy('relationship_to_head')
            ->orderBy('date_of_birth')
            ->get();
    }
    
    /**
     * Sync the family's head_of_family field from active members
     *
     * @param string $familyId
     * @return void
     */
    public function syncHeadOfFamily(string $familyId): void
    {
        $family = Family::find($familyId);
        if (!$family) {
            return;
        }
        
        // Find active member with relationship='self' or 'head'
        $headMember = FamilyMember::where('family_id', $familyId)
            ->whereIn('relationship_to_head', ['self', 'head'])
            ->where('status', 'active')
            ->first();
        
        if ($headMember) {
            // Update head_of_family to match the active head member's full name
            $family->update([
                'head_of_family' => trim("{$headMember->first_name} {$headMember->last_name}")
            ]);
        } else {
            // If no active head member, try to find any member with relationship='self' or 'head'
            $headMember = FamilyMember::where('family_id', $familyId)
                ->whereIn('relationship_to_head', ['self', 'head'])
                ->first();
            
            if ($headMember) {
                $family->update([
                    'head_of_family' => trim("{$headMember->first_name} {$headMember->last_name}")
                ]);
            }
        }
    }
}


