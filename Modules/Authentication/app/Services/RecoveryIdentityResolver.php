<?php

namespace Modules\Authentication\Services;

use Illuminate\Support\Collection;
use Modules\Authentication\Models\User;
use Modules\Authentication\Support\RecoveryEmailNormalizer;
use Modules\Tenants\Models\Tenant;

class RecoveryIdentityResolver
{
    public function normalizeEmail(string $email): string
    {
        return RecoveryEmailNormalizer::normalize($email);
    }

    /**
     * @return Collection<int, User>
     */
    public function findUsersByNormalizedEmail(string $normalizedEmail): Collection
    {
        return User::query()
            ->with(['role', 'tenant'])
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->get();
    }

    public function resolveEligibleUser(string $normalizedEmail): ?User
    {
        $matches = $this->findUsersByNormalizedEmail($normalizedEmail);

        if ($matches->count() !== 1) {
            return null;
        }

        $user = $matches->first();

        if (! $user instanceof User || ! $this->isEligible($user)) {
            return null;
        }

        return $user;
    }

    public function isEligible(User $user): bool
    {
        if ($user->trashed() || (int) $user->active !== 1) {
            return false;
        }

        $email = trim((string) $user->email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        if ($user->role && (int) $user->role->active !== 1) {
            return false;
        }

        if ($user->tenant_id !== null) {
            $tenant = $user->relationLoaded('tenant')
                ? $user->tenant
                : Tenant::query()->find($user->tenant_id);

            if (! $tenant || $tenant->trashed()) {
                return false;
            }
        }

        return true;
    }
}
