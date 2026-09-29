<?php

namespace Modules\PastoralCare\Policies;

use Modules\Authentication\Models\User;
use Modules\PastoralCare\Models\PastoralCareRequest;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;

class PastoralCareRequestPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'pastoral.care.view');
    }

    public function view(User $user, PastoralCareRequest $request): bool
    {
        return $this->allows($user, 'pastoral.care.view')
            && $this->matchesTenant($user, $request->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'pastoral.care.create');
    }

    public function assign(User $user, PastoralCareRequest $request): bool
    {
        return $this->allows($user, 'pastoral.care.assign')
            && $this->matchesTenant($user, $request->tenant_id);
    }

    public function complete(User $user, PastoralCareRequest $request): bool
    {
        return $this->allows($user, 'pastoral.care.view')
            && $this->matchesTenant($user, $request->tenant_id);
    }

    public function cancel(User $user, PastoralCareRequest $request): bool
    {
        return $this->allows($user, 'pastoral.care.assign')
            && $this->matchesTenant($user, $request->tenant_id);
    }
}
