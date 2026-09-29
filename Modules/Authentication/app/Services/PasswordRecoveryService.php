<?php

namespace Modules\Authentication\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authentication\Models\PasswordRecoveryRequest;
use Modules\Authentication\Models\PasswordRecoveryThrottle;
use Modules\Authentication\Models\User;

class PasswordRecoveryService
{
    public const GENERIC_REQUEST_MESSAGE = 'Your password recovery request has been submitted for verification. If the request is valid, further instructions will be sent to your registered email address.';

    public function __construct(
        private readonly RecoveryIdentityResolver $identityResolver,
        private readonly PasswordRecoveryApproverResolver $approverResolver,
        private readonly PasswordRecoveryRequestService $requestService,
        private readonly PasswordRecoveryAuditService $audit,
    ) {
    }

    /**
     * @return array{message: string}
     */
    public function requestRecovery(string $email): array
    {
        $normalized = $this->identityResolver->normalizeEmail($email);
        $user = $this->identityResolver->resolveEligibleUser($normalized);

        if ($user !== null && $this->isSupportAdmin($user)) {
            $user = null;
        }

        if ($user !== null && $this->hasExceededDailyLimit($user)) {
            $this->audit->record('user.password_recovery_daily_limit', $user, [
                'masked_identifier' => $this->audit->maskedEmail($normalized),
            ]);

            return $this->genericResponse();
        }

        if ($user !== null && $this->requestService->hasInflightRequest($user->id)) {
            return $this->genericResponse();
        }

        if ($user !== null) {
            $classification = $this->approverResolver->classifyRequester($user);

            if ($classification === PasswordRecoveryRequest::CLASSIFICATION_TENANT_USER) {
                $primaryAdmin = $this->approverResolver->resolveTenantPrimaryAdmin((int) $user->tenant_id);
                if ($primaryAdmin === null) {
                    $this->audit->record('user.password_recovery_failed', $user, [
                        'masked_identifier' => $this->audit->maskedEmail($normalized),
                        'reason_code' => 'NO_APPROVER',
                    ]);

                    return $this->genericResponse();
                }
            }

            if (in_array($classification, [
                PasswordRecoveryRequest::CLASSIFICATION_EKKLESIA_USER,
                PasswordRecoveryRequest::CLASSIFICATION_TENANT_ADMIN,
            ], true) && $this->approverResolver->resolveSuperAdmins()->isEmpty()) {
                $this->audit->record('user.password_recovery_failed', $user, [
                    'masked_identifier' => $this->audit->maskedEmail($normalized),
                    'reason_code' => 'NO_APPROVER',
                ]);

                return $this->genericResponse();
            }

            $request = $this->createRequest($user, $normalized, $classification);

            $this->incrementDailyInitiation($user);

            $this->audit->record('user.password_recovery_requested', $user, [
                'masked_identifier' => $this->audit->maskedEmail($normalized),
                'request_id' => $request->id,
                'classification' => $classification,
            ]);

            if ($classification === PasswordRecoveryRequest::CLASSIFICATION_SUPER_ADMIN) {
                dispatch(function () use ($user, $request) {
                    $freshUser = User::query()->find($user->id);
                    $freshRequest = PasswordRecoveryRequest::query()->find($request->id);
                    if ($freshUser && $freshRequest) {
                        $this->requestService->processSuperAdminDirectRecovery($freshUser, $freshRequest);
                    }
                })->afterResponse();
            } else {
                $this->requestService->notifyApprovers($request, $user);
            }
        }

        return $this->genericResponse();
    }

    /**
     * @return array{message: string}
     */
    private function genericResponse(): array
    {
        return ['message' => self::GENERIC_REQUEST_MESSAGE];
    }

    private function createRequest(User $user, string $normalizedEmail, string $classification): PasswordRecoveryRequest
    {
        $ttlHours = (int) config('authentication.recovery.request_ttl_hours', 24);
        $intendedApproverId = null;

        if ($classification === PasswordRecoveryRequest::CLASSIFICATION_TENANT_USER) {
            $intendedApproverId = $this->approverResolver
                ->resolveTenantPrimaryAdmin((int) $user->tenant_id)?->id;
        }

        return PasswordRecoveryRequest::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'requester_email' => $normalizedEmail,
            'requester_domain' => $this->approverResolver->resolveDomain($user),
            'requester_classification' => $classification,
            'intended_approver_user_id' => $intendedApproverId,
            'status' => PasswordRecoveryRequest::STATUS_PENDING_APPROVAL,
            'request_ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'expires_at' => now()->addHours($ttlHours),
        ]);
    }

    private function isSupportAdmin(User $user): bool
    {
        return $user->activeRoles()
            ->where('name', \Modules\Authentication\Models\Role::SUPPORT_ADMIN)
            ->exists();
    }

    private function hasExceededDailyLimit(User $user): bool
    {
        $today = now()->utc()->toDateString();
        $dailyLimit = (int) config('authentication.recovery.daily_initiations', 3);

        $throttle = PasswordRecoveryThrottle::query()->find($user->id);
        if ($throttle === null) {
            return false;
        }

        if ($throttle->window_date?->toDateString() !== $today) {
            return false;
        }

        return (int) $throttle->initiation_count >= $dailyLimit;
    }

    private function incrementDailyInitiation(User $user): void
    {
        $today = now()->utc()->toDateString();

        DB::transaction(function () use ($user, $today) {
            $throttle = PasswordRecoveryThrottle::query()->lockForUpdate()->find($user->id);

            if ($throttle === null) {
                PasswordRecoveryThrottle::query()->create([
                    'user_id' => $user->id,
                    'window_date' => $today,
                    'initiation_count' => 1,
                    'updated_at' => now(),
                ]);

                return;
            }

            if ($throttle->window_date?->toDateString() !== $today) {
                $throttle->window_date = $today;
                $throttle->initiation_count = 0;
            }

            $throttle->initiation_count = (int) $throttle->initiation_count + 1;
            $throttle->updated_at = now();
            $throttle->save();
        });
    }
}
