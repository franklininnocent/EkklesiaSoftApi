<?php

namespace Modules\Family\Policies;

use Modules\Authentication\Models\User;
use Modules\Family\app\Services\ParishionerFamilyAccessService;
use Modules\Family\Models\Family;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;

class FamilyPolicy
{
    use AuthorizesTenantPermission;

    public function __construct(
        private readonly ParishionerFamilyAccessService $parishionerAccess,
    ) {
    }

    public function viewAny(User $user): bool
    {
        if ($this->parishionerAccess->isParishioner($user)) {
            return $this->matchesTenant($user, $user->tenant_id);
        }

        return $this->allows($user, 'families.view');
    }

    public function view(User $user, Family $family): bool
    {
        if (! $this->matchesTenant($user, $family->tenant_id)) {
            return false;
        }

        if ($this->parishionerAccess->canViewFamily($user, $family)) {
            return true;
        }

        return $this->allows($user, 'families.view');
    }

    public function create(User $user): bool
    {
        return $this->parishionerAccess->canMutateFamily($user)
            && $this->allows($user, 'families.create');
    }

    public function update(User $user, Family $family): bool
    {
        return $this->parishionerAccess->canMutateFamily($user, $family)
            && $this->allows($user, 'families.edit')
            && $this->matchesTenant($user, $family->tenant_id);
    }

    public function delete(User $user, Family $family): bool
    {
        return $this->parishionerAccess->canMutateFamily($user, $family)
            && $this->allows($user, 'families.delete')
            && $this->matchesTenant($user, $family->tenant_id);
    }
}
