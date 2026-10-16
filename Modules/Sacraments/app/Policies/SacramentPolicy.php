<?php

namespace Modules\Sacraments\Policies;

use Modules\Authentication\Models\User;
use Modules\Sacraments\Models\Sacrament;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;

class SacramentPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'sacraments.view');
    }

    public function view(User $user, Sacrament $sacrament): bool
    {
        return $this->allows($user, 'sacraments.view')
            && $this->matchesTenant($user, $sacrament->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'sacraments.create');
    }

    public function update(User $user, Sacrament $sacrament): bool
    {
        return $this->allows($user, 'sacraments.edit')
            && $this->matchesTenant($user, $sacrament->tenant_id);
    }

    public function delete(User $user, Sacrament $sacrament): bool
    {
        return $this->allows($user, 'sacraments.delete')
            && $this->matchesTenant($user, $sacrament->tenant_id);
    }

    public function void(User $user, Sacrament $sacrament): bool
    {
        return $this->allows($user, 'sacraments.void')
            && $this->matchesTenant($user, $sacrament->tenant_id);
    }

    public function correct(User $user, Sacrament $sacrament): bool
    {
        return $this->allows($user, 'sacraments.correct')
            && $this->matchesTenant($user, $sacrament->tenant_id);
    }

    public function restore(User $user, Sacrament $sacrament): bool
    {
        return $this->allows($user, 'sacraments.restore')
            && $this->matchesTenant($user, $sacrament->tenant_id);
    }
}
