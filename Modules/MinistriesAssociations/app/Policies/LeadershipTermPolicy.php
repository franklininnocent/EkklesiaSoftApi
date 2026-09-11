<?php

namespace Modules\MinistriesAssociations\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;
use Modules\Tenants\Support\EffectiveTenant;
use Modules\MinistriesAssociations\Models\LeadershipTerm;

class LeadershipTermPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'ministries.view');
    }

    public function view(User $user, LeadershipTerm $term): bool
    {
        return $this->allows($user, 'ministries.view')
            && EffectiveTenant::matches($user, $term->tenant_id);
    }

    public function assign(User $user): bool
    {
        return $this->allows($user, 'ministries.manage_leadership');
    }

    public function handover(User $user): bool
    {
        return $this->allows($user, 'ministries.manage_leadership');
    }

    public function terminate(User $user, LeadershipTerm $term): bool
    {
        return $this->allows($user, 'ministries.manage_leadership')
            && EffectiveTenant::matches($user, $term->tenant_id);
    }
}
