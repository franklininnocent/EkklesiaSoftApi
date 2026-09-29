<?php

namespace Modules\MinistriesAssociations\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;
use Modules\Tenants\Support\EffectiveTenant;
use Modules\MinistriesAssociations\Models\OrganizationType;

class OrganizationTypePolicy
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

    public function update(User $user, OrganizationType $type): bool
    {
        return $this->allows($user, 'ministries.configure')
            && EffectiveTenant::matches($user, $type->tenant_id);
    }

    public function updateStatus(User $user, OrganizationType $type): bool
    {
        return $this->update($user, $type);
    }

    public function seedDefaults(User $user): bool
    {
        return $this->allows($user, 'ministries.configure');
    }
}
