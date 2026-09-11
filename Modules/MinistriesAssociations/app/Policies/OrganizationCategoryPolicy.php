<?php

namespace Modules\MinistriesAssociations\Policies;

use Modules\Authentication\Models\User;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;
use Modules\Tenants\Support\EffectiveTenant;

class OrganizationCategoryPolicy
{
    use AuthorizesTenantPermission;
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'ministries.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'ministries.configure');
    }

    public function update(User $user, OrganizationCategory $category): bool
    {
        return $this->allows($user, 'ministries.configure')
            && EffectiveTenant::matches($user, $category->tenant_id);
    }

    public function updateStatus(User $user, OrganizationCategory $category): bool
    {
        return $this->update($user, $category);
    }

    public function seedDefaults(User $user): bool
    {
        return $this->allows($user, 'ministries.configure');
    }

}
