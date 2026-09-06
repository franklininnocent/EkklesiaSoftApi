<?php

namespace Modules\Family\Policies;

use Modules\Authentication\Models\User;
use Modules\Family\Models\Person;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;

class PersonPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'families.view');
    }

    public function view(User $user, Person $person): bool
    {
        return $this->allows($user, 'families.view')
            && $this->matchesTenant($user, $person->tenant_id);
    }

    public function reconcile(User $user, Person $person): bool
    {
        return $this->allows($user, 'families.edit')
            && $this->matchesTenant($user, $person->tenant_id);
    }
}
