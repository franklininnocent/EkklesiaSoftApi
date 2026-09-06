<?php

namespace Modules\Family\app\Repositories;

use App\Support\CaseInsensitiveSearch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Services\ParishMemberMissingSacramentQuery;
use Modules\Family\Support\ParishProgressionFilter;

class FamilyRepository
{
    /**
     * Get paginated families for a tenant with optional filters
     */
    public function getPaginatedFamilies(string $tenantId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Family::where('tenant_id', $tenantId)
            ->with([
                'bcc:id,name',
                'members' => fn ($memberQuery) => $memberQuery->select([
                    'id',
                    'family_id',
                    'first_name',
                    'last_name',
                    'relationship_to_head',
                    'is_primary_contact',
                    'status',
                    'phone',
                    'email',
                ])->where('status', 'active'),
            ]);

        if (! empty($filters['family_id'])) {
            $query->where('id', $filters['family_id']);
        }

        // Apply filters
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $pattern = "%{$search}%";
            $query->where(function ($q) use ($pattern) {
                CaseInsensitiveSearch::applyColumnLike($q, 'family_name', $pattern);
                CaseInsensitiveSearch::applyColumnLike($q, 'family_code', $pattern, 'or');
                CaseInsensitiveSearch::applyColumnLike($q, 'head_of_family', $pattern, 'or');
                $q->orWhereHas('members', function ($memberQuery) use ($pattern) {
                    $memberQuery->where(function ($mq) use ($pattern) {
                        CaseInsensitiveSearch::applyMemberFullNameLike($mq, $pattern);
                        CaseInsensitiveSearch::applyColumnLike($mq, 'phone', $pattern, 'or');
                        CaseInsensitiveSearch::applyColumnLike($mq, 'email', $pattern, 'or');
                    });
                });
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['bcc_id'])) {
            $query->where('bcc_id', $filters['bcc_id']);
        }

        // Parish Zone removed

        if (! empty($filters['city'])) {
            CaseInsensitiveSearch::applyColumnLike($query, 'city', "%{$filters['city']}%");
        }

        if (! empty($filters['missing_sacrament'])) {
            $familyIds = app(ParishMemberMissingSacramentQuery::class)->familyIdsForMissingSacrament(
                $tenantId,
                (string) $filters['missing_sacrament'],
                ! empty($filters['bcc_id']) ? (string) $filters['bcc_id'] : null
            );

            if ($familyIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('id', $familyIds);
            }
        } elseif (! empty($filters['progression'])) {
            $progression = ParishProgressionFilter::normalize((string) $filters['progression']);
            if ($progression === null) {
                $query->whereRaw('1 = 0');
            } else {
                $familyIds = app(ParishMemberMissingSacramentQuery::class)->familyIdsForProgression(
                    $tenantId,
                    $progression,
                    ! empty($filters['bcc_id']) ? (string) $filters['bcc_id'] : null
                );

                if ($familyIds === []) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('id', $familyIds);
                }
            }
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';

        // Handle sorting by BCC name (requires subquery to avoid join conflicts)
        if ($sortBy === 'bcc_name') {
            $query->orderByRaw(
                "(SELECT name FROM bccs WHERE bccs.id = families.bcc_id LIMIT 1) {$sortOrder}"
            );
        } else {
            // Direct column sorting (head_of_family, family_code, created_at, etc.)
            $query->orderBy($sortBy, $sortOrder);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get all families for a tenant
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
                'updater:id,name',
            ])
            ->first();
    }

    /**
     * Find family by family code
     */
    public function findByFamilyCode(string $familyCode, string $tenantId): ?Family
    {
        return Family::where('family_code', $familyCode)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * Create a new family
     */
    public function create(array $data): Family
    {
        return Family::create($data);
    }

    /**
     * Update family
     */
    public function update(Family $family, array $data): bool
    {
        return $family->update($data);
    }

    /**
     * Delete family (soft delete)
     */
    public function delete(Family $family): ?bool
    {
        return $family->delete();
    }

    /**
     * Get families by BCC
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
     */
    public function addMember(Family $family, array $memberData): FamilyMember
    {
        $memberData['family_id'] = $family->id;
        if (! array_key_exists('baptism_priest_is_home', $memberData) || $memberData['baptism_priest_is_home'] === null) {
            $memberData['baptism_priest_is_home'] = false;
        }
        $member = FamilyMember::create($memberData);

        // Auto-sync head_of_family if this member is designated as head
        if (isset($memberData['relationship_to_head']) && in_array(strtolower($memberData['relationship_to_head']), ['self', 'head'])) {
            $this->syncHeadOfFamily($family->id);
        }

        return $member;
    }

    /**
     * Update family member
     */
    public function updateMember(FamilyMember $member, array $data): bool
    {
        // CRITICAL: Ensure we're updating an existing model, not creating a new one
        if (! $member->exists) {
            \Log::error('Attempted to update non-existent member model', [
                'member_id' => $member->id ?? 'none',
                'family_id' => $member->family_id ?? 'none',
            ]);

            return false;
        }

        // Store original ID to verify it doesn't change
        $originalId = $member->id;

        $updated = $member->update($data);

        if ($updated) {
            // Verify the ID hasn't changed (should never happen, but safety check)
            if ($member->id !== $originalId) {
                \Log::error('CRITICAL: Member ID changed during update - this should never happen!', [
                    'original_id' => $originalId,
                    'new_id' => $member->id,
                    'family_id' => $member->family_id,
                ]);

                return false;
            }

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
     */
    public function deleteMember(FamilyMember $member): ?bool
    {
        return $member->delete();
    }

    /**
     * Get member by ID
     */
    public function findMemberById(string $memberId, string $familyId): ?FamilyMember
    {
        return FamilyMember::where('id', $memberId)
            ->where('family_id', $familyId)
            ->first();
    }

    /**
     * Get all members of a family
     */
    public function getFamilyMembers(string $familyId): Collection
    {
        return FamilyMember::where('family_id', $familyId)
            ->with('person:id,date_of_birth,gender,father_name,mother_name')
            ->orderBy('relationship_to_head')
            ->orderBy('date_of_birth')
            ->get();
    }

    /**
     * Sync the family's head_of_family field from active members
     */
    public function syncHeadOfFamily(string $familyId): void
    {
        $family = Family::find($familyId);
        if (! $family) {
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
                'head_of_family' => trim("{$headMember->first_name} {$headMember->last_name}"),
            ]);
        } else {
            // If no active head member, try to find any member with relationship='self' or 'head'
            $headMember = FamilyMember::where('family_id', $familyId)
                ->whereIn('relationship_to_head', ['self', 'head'])
                ->first();

            if ($headMember) {
                $family->update([
                    'head_of_family' => trim("{$headMember->first_name} {$headMember->last_name}"),
                ]);
            }
        }
    }

    /**
     * Get all members across all families for a tenant with pagination and filters
     */
    public function getAllMembers(string $tenantId, array $filters = [], int $perPage = 10, int $page = 1): LengthAwarePaginator
    {
        $query = FamilyMember::whereHas('family', function ($q) use ($tenantId) {
            $q->where('tenant_id', $tenantId);
        })
            ->with(['family' => function ($q) {
                $q->with(['bcc:id,name,bcc_code']);
            }, 'person:id,date_of_birth,gender,father_name,mother_name']);

        // Apply search filter
        if (! empty($filters['search'])) {
            $pattern = "%{$filters['search']}%";
            $query->where(function ($q) use ($pattern) {
                CaseInsensitiveSearch::applyMemberFullNameLike($q, $pattern);
                CaseInsensitiveSearch::applyColumnLike($q, 'first_name', $pattern, 'or');
                CaseInsensitiveSearch::applyColumnLike($q, 'last_name', $pattern, 'or');
                CaseInsensitiveSearch::applyColumnLike($q, 'phone', $pattern, 'or');
                CaseInsensitiveSearch::applyColumnLike($q, 'email', $pattern, 'or');
            });
        }

        // Apply status filter
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Apply BCC filter
        if (! empty($filters['bcc_id'])) {
            $query->whereHas('family', function ($q) use ($filters) {
                $q->where('bcc_id', $filters['bcc_id']);
            });
        }

        // Apply relationship filter (to identify family heads)
        if (! empty($filters['is_head'])) {
            if ($filters['is_head'] === 'true' || $filters['is_head'] === true) {
                $query->whereIn('relationship_to_head', ['self', 'head']);
            }
        }

        if (! empty($filters['progression'])) {
            $progression = ParishProgressionFilter::normalize((string) $filters['progression']);
            if ($progression === null) {
                $query->whereRaw('1 = 0');
            } else {
                $memberIds = app(ParishMemberMissingSacramentQuery::class)->memberIdsForProgression(
                    $tenantId,
                    $progression,
                    ! empty($filters['bcc_id']) ? (string) $filters['bcc_id'] : null
                );

                if ($memberIds === []) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('family_members.id', $memberIds);
                }
            }
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'last_name';
        $sortOrder = $filters['sort_order'] ?? 'asc';

        // Handle special sorting cases
        if ($sortBy === 'name') {
            $query->orderBy('last_name', $sortOrder)
                ->orderBy('first_name', $sortOrder);
        } elseif ($sortBy === 'address') {
            // Sort by family address (address_line_1)
            $query->join('families', 'family_members.family_id', '=', 'families.id')
                ->orderBy('families.address_line_1', $sortOrder)
                ->select('family_members.*'); // Select only member columns to avoid conflicts
        } elseif ($sortBy === 'bcc') {
            // Sort by BCC name
            $query->join('families', 'family_members.family_id', '=', 'families.id')
                ->leftJoin('bccs', 'families.bcc_id', '=', 'bccs.id')
                ->orderBy('bccs.name', $sortOrder)
                ->select('family_members.*'); // Select only member columns to avoid conflicts
        } elseif ($sortBy === 'father_name') {
            // Sort by father's name - this requires a subquery or complex join
            // For now, we'll sort by a placeholder or skip sorting by father_name
            // Note: This is complex as father_name is not a direct column
            // We'll need to implement a subquery or handle it differently
            // For simplicity, we'll sort by last_name as a fallback
            $query->orderBy('last_name', $sortOrder);
        } else {
            // Direct column sorting (last_name, first_name, etc.)
            $query->orderBy($sortBy, $sortOrder);
        }

        // Use paginate with explicit page number
        return $query->paginate($perPage, ['*'], 'page', $page);
    }
}
