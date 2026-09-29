<?php

namespace Modules\EcclesiasticalData\Policies;

use Modules\Authentication\Models\User;
use Modules\EcclesiasticalData\Models\BishopUpdateRequest;
use Modules\EcclesiasticalData\Policies\Concerns\AuthorizesEcclesiasticalPermission;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;

class BishopUpdateRequestPolicy
{
    use AuthorizesEcclesiasticalPermission;
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        if ($user->hasEkklesiaRole()) {
            return $this->allowsPlatform($user, 'bishops.review_requests');
        }

        return $this->allows($user, 'bishops.view_own_requests')
            && $this->matchesTenant($user, $user->tenant_id);
    }

    public function view(User $user, BishopUpdateRequest $request): bool
    {
        if ($user->hasEkklesiaRole()) {
            return $this->allowsPlatform($user, 'bishops.review_requests');
        }

        return $this->allows($user, 'bishops.view_own_requests')
            && $this->matchesTenant($user, $request->tenant_id);
    }

    public function create(User $user): bool
    {
        if ($user->hasEkklesiaRole()) {
            return false;
        }

        return $this->allows($user, 'bishops.submit_update_request')
            && $this->matchesTenant($user, $user->tenant_id)
            && $this->tenantHasDiocese($user);
    }

    public function update(User $user, BishopUpdateRequest $request): bool
    {
        if ($user->hasEkklesiaRole()) {
            return false;
        }

        return $this->allows($user, 'bishops.submit_update_request')
            && $this->matchesTenant($user, $request->tenant_id)
            && $request->isEditableBySubmitter();
    }

    public function submit(User $user, BishopUpdateRequest $request): bool
    {
        return $this->update($user, $request);
    }

    public function approve(User $user, BishopUpdateRequest $request): bool
    {
        return $user->hasEkklesiaRole()
            && $this->allowsPlatform($user, 'bishops.approve_requests')
            && $request->isReviewable();
    }

    public function reject(User $user, BishopUpdateRequest $request): bool
    {
        return $user->hasEkklesiaRole()
            && $this->allowsPlatform($user, 'bishops.reject_requests')
            && $request->isReviewable();
    }

    public function requestClarification(User $user, BishopUpdateRequest $request): bool
    {
        return $user->hasEkklesiaRole()
            && $this->allowsPlatform($user, 'bishops.request_clarification')
            && $request->isReviewable();
    }

    public function viewInternalNotes(User $user, BishopUpdateRequest $request): bool
    {
        return $user->hasEkklesiaRole()
            && $this->allowsPlatform($user, 'bishops.review_requests');
    }

    private function tenantHasDiocese(User $user): bool
    {
        if (! $user->tenant_id) {
            return false;
        }

        return ChurchProfile::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereNotNull('archdiocese_id')
            ->exists();
    }
}
