<?php

namespace Modules\Family\app\Services;

use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Support\LeadershipRoleCategory;

class ParishionerFamilyAccessService
{
    public function isParishioner(User $user): bool
    {
        if ($user->isTenantAdmin() || $user->isSuperAdmin() || $user->isEkklesiaAdmin()) {
            return false;
        }

        if (empty($user->person_id)) {
            return false;
        }

        if ($this->hasActiveParishClergyAssignment($user)) {
            return false;
        }

        return true;
    }

    public function canViewFamily(User $user, Family $family): bool
    {
        if (! $this->isParishioner($user)) {
            return true;
        }

        return $this->isActiveMemberOfFamily($user, $family->id);
    }

    public function canMutateFamily(User $user, ?Family $family = null): bool
    {
        return ! $this->isParishioner($user);
    }

    public function activeFamilyIdForUser(User $user): ?string
    {
        if (empty($user->person_id)) {
            return null;
        }

        $member = FamilyMember::query()
            ->where('person_id', $user->person_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();

        return $member?->family_id;
    }

    private function isActiveMemberOfFamily(User $user, string $familyId): bool
    {
        if (empty($user->person_id)) {
            return false;
        }

        return FamilyMember::query()
            ->where('person_id', $user->person_id)
            ->where('family_id', $familyId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();
    }

    private function hasActiveParishClergyAssignment(User $user): bool
    {
        if (empty($user->person_id) || empty($user->tenant_id)) {
            return false;
        }

        return LeadershipAssignment::query()
            ->forTenant((int) $user->tenant_id)
            ->where('person_id', $user->person_id)
            ->active()
            ->whereHas('role', fn ($roleQuery) => $roleQuery->where('category', LeadershipRoleCategory::PARISH_CLERGY))
            ->exists();
    }
}
