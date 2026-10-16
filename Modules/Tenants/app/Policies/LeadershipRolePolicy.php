<?php

namespace Modules\Tenants\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;

class LeadershipRolePolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return app(\Modules\Tenants\Support\TenantContext::class)->effectiveTenantId() !== null;
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'church.leadership.roles.create');
    }

    public function update(User $user, LeadershipRole $role): bool
    {
        if ($role->tenant_id === null) {
            return false;
        }

        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->effectiveTenantId();

        return $tenantId !== null
            && (int) $role->tenant_id === (int) $tenantId
            && $this->allows($user, 'church.leadership.roles.create');
    }
}
