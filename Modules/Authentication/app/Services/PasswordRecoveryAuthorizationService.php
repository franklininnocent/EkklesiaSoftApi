<?php

namespace Modules\Authentication\Services;

use Modules\Authentication\Models\PasswordRecoveryRequest;
use Modules\Authentication\Models\User;
use Modules\Tenants\Support\TenantContext;

class PasswordRecoveryAuthorizationService
{
    public const PERMISSION_VIEW = 'password.recovery.requests.view';

    public const PERMISSION_PROCESS = 'password.recovery.requests.process';

    public function canViewRequests(User $actor): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        return (bool) $actor->is_primary_admin
            && $actor->hasPermission(self::PERMISSION_VIEW);
    }

    public function canApprove(User $actor, PasswordRecoveryRequest $request): bool
    {
        return $this->authorizeAction($actor, $request, 'approve');
    }

    public function canReject(User $actor, PasswordRecoveryRequest $request): bool
    {
        return $this->authorizeAction($actor, $request, 'reject');
    }

    public function canRetryDelivery(User $actor, PasswordRecoveryRequest $request): bool
    {
        return $this->authorizeAction($actor, $request, 'retry');
    }

    public function scopeVisibleToActor(User $actor, $query)
    {
        if ($actor->isSuperAdmin()) {
            return $query->whereIn('requester_classification', [
                PasswordRecoveryRequest::CLASSIFICATION_SUPER_ADMIN,
                PasswordRecoveryRequest::CLASSIFICATION_EKKLESIA_USER,
                PasswordRecoveryRequest::CLASSIFICATION_TENANT_ADMIN,
            ]);
        }

        if ($actor->is_primary_admin && $actor->tenant_id !== null) {
            return $query
                ->where('tenant_id', $actor->tenant_id)
                ->where('requester_classification', PasswordRecoveryRequest::CLASSIFICATION_TENANT_USER);
        }

        return $query->whereRaw('1 = 0');
    }

    private function authorizeAction(User $actor, PasswordRecoveryRequest $request, string $action): bool
    {
        if ($this->isSupportSessionActor($actor)) {
            return false;
        }

        if ($actor->id === $request->user_id) {
            return false;
        }

        $target = User::query()->with(['role', 'tenant'])->find($request->user_id);
        if ($target === null || ! $this->isEligibleTarget($target)) {
            return false;
        }

        if ($request->isExpired()) {
            return false;
        }

        if ($action === 'approve' && ! $request->canApprove()) {
            return false;
        }

        if ($action === 'reject' && ! $request->canReject()) {
            return false;
        }

        if ($action === 'retry' && ! $request->canRetryDelivery()) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return in_array($request->requester_classification, [
                PasswordRecoveryRequest::CLASSIFICATION_SUPER_ADMIN,
                PasswordRecoveryRequest::CLASSIFICATION_EKKLESIA_USER,
                PasswordRecoveryRequest::CLASSIFICATION_TENANT_ADMIN,
            ], true);
        }

        if ($actor->is_primary_admin && $actor->tenant_id !== null) {
            if (! $actor->hasPermission(self::PERMISSION_PROCESS)) {
                return false;
            }

            return $request->requester_classification === PasswordRecoveryRequest::CLASSIFICATION_TENANT_USER
                && (int) $request->tenant_id === (int) $actor->tenant_id
                && (int) $target->tenant_id === (int) $actor->tenant_id;
        }

        return false;
    }

    private function isSupportSessionActor(User $actor): bool
    {
        $context = app(TenantContext::class);

        return $context->isSupportSession();
    }

    private function isEligibleTarget(User $user): bool
    {
        if ($user->trashed() || (int) $user->active !== 1) {
            return false;
        }

        $email = trim((string) $user->email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
