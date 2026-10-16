<?php

namespace Modules\Authentication\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Authentication\Mail\PasswordRecoveryRequestNotificationMail;
use Modules\Authentication\Models\PasswordRecoveryRequest;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;
use RuntimeException;
use Throwable;

class PasswordRecoveryRequestService
{
    public function __construct(
        private readonly RecoveryIdentityResolver $identityResolver,
        private readonly PasswordRecoveryApproverResolver $approverResolver,
        private readonly PasswordRecoveryAuthorizationService $authorization,
        private readonly SecurePasswordGenerator $passwordGenerator,
        private readonly PasswordRecoveryDeliveryService $delivery,
        private readonly PasswordManagementService $passwordManagement,
        private readonly PasswordRecoveryAuditService $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listForActor(User $actor, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = PasswordRecoveryRequest::query()
            ->with(['user.role', 'tenant', 'processedBy'])
            ->orderByDesc('created_at');

        $this->authorization->scopeVisibleToActor($actor, $query);

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['email'])) {
            $email = strtolower(trim((string) $filters['email']));
            $query->where(function ($inner) use ($email) {
                $inner->where('requester_email', 'like', '%'.$email.'%')
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->whereRaw('LOWER(email) LIKE ?', ['%'.$email.'%']));
            });
        }

        if (! empty($filters['tenant_id']) && $actor->isSuperAdmin()) {
            $query->where('tenant_id', (int) $filters['tenant_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->paginate(min(100, max(1, $perPage)));
    }

    public function showForActor(User $actor, string $requestId): PasswordRecoveryRequest
    {
        $request = PasswordRecoveryRequest::query()
            ->with(['user.role', 'tenant', 'processedBy', 'intendedApprover'])
            ->whereKey($requestId)
            ->firstOrFail();

        $visible = PasswordRecoveryRequest::query()->whereKey($requestId);
        $this->authorization->scopeVisibleToActor($actor, $visible);

        if (! $visible->exists()) {
            throw new RuntimeException('Password recovery request not found.', 404);
        }

        return $request;
    }

    public function approve(User $actor, string $requestId): PasswordRecoveryRequest
    {
        if (! $this->authorization->canApprove($actor, $this->findRequestOrFail($requestId))) {
            throw new RuntimeException('You are not authorized to approve this request.', 403);
        }

        return $this->processApproval($actor, $requestId, hashFirst: true);
    }

    public function reject(User $actor, string $requestId, ?string $reason = null): PasswordRecoveryRequest
    {
        $request = $this->findRequestOrFail($requestId);

        if (! $this->authorization->canReject($actor, $request)) {
            throw new RuntimeException('You are not authorized to reject this request.', 403);
        }

        $result = DB::transaction(function () use ($actor, $request, $reason) {
            $locked = PasswordRecoveryRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->canReject()) {
                throw new RuntimeException('This request cannot be rejected.', 422);
            }

            $locked->status = PasswordRecoveryRequest::STATUS_REJECTED;
            $locked->processed_by_user_id = $actor->id;
            $locked->processed_at = now();
            $locked->rejection_reason = $reason;
            $locked->save();

            $this->auditApproval('user.password_recovery_rejected', $actor, $locked);

            return $locked->fresh(['user.role', 'tenant']);
        });

        $this->completeApproverNotificationSubject((string) $result->id);

        return $result;
    }

    public function retryDelivery(User $actor, string $requestId): PasswordRecoveryRequest
    {
        $request = $this->findRequestOrFail($requestId);

        if (! $this->authorization->canRetryDelivery($actor, $request)) {
            throw new RuntimeException('You are not authorized to retry delivery for this request.', 403);
        }

        return $this->processApproval($actor, $requestId, hashFirst: true, isRetry: true);
    }

    public function processSuperAdminDirectRecovery(User $user, PasswordRecoveryRequest $request): void
    {
        $request->status = PasswordRecoveryRequest::STATUS_PROCESSING;
        $request->processed_at = now();
        $request->save();

        $temporaryPassword = $this->passwordGenerator->generateForUser($user);

        try {
            $this->delivery->sendTemporaryPassword($user, $temporaryPassword);
        } catch (\Throwable) {
            $request->status = PasswordRecoveryRequest::STATUS_FAILED;
            $request->failure_reason = 'MAIL_TRANSPORT';
            $request->failed_at = now();
            $request->save();

            $this->audit->record('user.password_recovery_failed', $user, [
                'request_id' => $request->id,
                'reason_code' => 'MAIL_TRANSPORT',
            ]);

            return;
        }

        $this->passwordManagement->applyRecoveryTemporaryPassword($user, $temporaryPassword);

        $request->status = PasswordRecoveryRequest::STATUS_COMPLETED;
        $request->password_committed_at = now();
        $request->completed_at = now();
        $request->save();

        $this->audit->record('user.password_recovery_completed', $user, [
            'request_id' => $request->id,
            'classification' => $request->requester_classification,
        ]);
    }

    private function processApproval(User $actor, string $requestId, bool $hashFirst, bool $isRetry = false): PasswordRecoveryRequest
    {
        $temporaryPassword = null;
        $targetUser = null;

        $request = DB::transaction(function () use ($actor, $requestId, $hashFirst, $isRetry, &$temporaryPassword, &$targetUser) {
            $locked = PasswordRecoveryRequest::query()
                ->whereKey($requestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($isRetry && ! $locked->canRetryDelivery()) {
                throw new RuntimeException('This request cannot be retried.', 422);
            }

            if (! $isRetry && ! $locked->canApprove()) {
                throw new RuntimeException('This request cannot be approved.', 422);
            }

            if (! $this->authorization->canApprove($actor, $locked) && ! ($isRetry && $this->authorization->canRetryDelivery($actor, $locked))) {
                throw new RuntimeException('You are not authorized to process this request.', 403);
            }

            $targetUser = User::query()->with(['role', 'tenant'])->whereKey($locked->user_id)->lockForUpdate()->first();
            if ($targetUser === null || ! $this->identityResolver->isEligible($targetUser)) {
                $locked->status = PasswordRecoveryRequest::STATUS_FAILED;
                $locked->failure_reason = 'TARGET_INELIGIBLE';
                $locked->failed_at = now();
                $locked->save();

                throw new RuntimeException('The target user is no longer eligible for password recovery.', 422);
            }

            $locked->status = PasswordRecoveryRequest::STATUS_PROCESSING;
            $locked->processed_by_user_id = $actor->id;
            $locked->processed_at = now();
            $locked->save();

            $temporaryPassword = $this->passwordGenerator->generateForUser($targetUser);

            if ($hashFirst) {
                $this->passwordManagement->applyRecoveryTemporaryPassword($targetUser, $temporaryPassword);
                $locked->password_committed_at = now();
                $locked->save();
            }

            $this->auditApproval('user.password_recovery_approved', $actor, $locked);

            return $locked;
        });

        try {
            if ($targetUser === null || $temporaryPassword === null) {
                throw new RuntimeException('Unable to process password recovery.', 500);
            }

            if (! $hashFirst) {
                $this->delivery->sendTemporaryPassword($targetUser, $temporaryPassword);
                $this->passwordManagement->applyRecoveryTemporaryPassword($targetUser, $temporaryPassword);
            } else {
                $this->delivery->sendTemporaryPassword($targetUser, $temporaryPassword);
            }

            $request->status = PasswordRecoveryRequest::STATUS_COMPLETED;
            $request->completed_at = now();
            if (! $hashFirst) {
                $request->password_committed_at = now();
            }
            $request->save();

            $this->auditApproval('user.password_recovery_completed', $actor, $request);
        } catch (\Throwable) {
            if ($hashFirst) {
                $request->status = PasswordRecoveryRequest::STATUS_DELIVERY_FAILED;
                $request->failure_reason = 'MAIL_TRANSPORT';
                $request->failed_at = now();
                $request->save();

                $this->auditApproval('user.password_recovery_failed', $actor, $request, [
                    'reason_code' => 'MAIL_TRANSPORT',
                ]);
            } else {
                $request->status = PasswordRecoveryRequest::STATUS_FAILED;
                $request->failure_reason = 'MAIL_TRANSPORT';
                $request->failed_at = now();
                $request->save();
            }

            $this->completeApproverNotificationSubject((string) $request->id);

            throw new RuntimeException('A temporary password was generated but the email could not be delivered. Please retry from the request list.', 422);
        }

        $this->completeApproverNotificationSubject((string) $request->id);

        return $request->fresh(['user.role', 'tenant', 'processedBy']);
    }

    public function notifyApprovers(PasswordRecoveryRequest $request, User $target): void
    {
        $approvers = $this->resolveApproverRecipients($request);
        if ($approvers->isEmpty()) {
            return;
        }

        $tenantName = null;
        if ($target->tenant_id !== null) {
            $tenantName = Tenant::query()->find($target->tenant_id)?->name;
        }

        $roleLabel = $this->roleLabelForClassification($request->requester_classification);
        $requestedAt = $request->created_at?->toIso8601String() ?? now()->toIso8601String();

        if (config('authentication.recovery.mail_enabled', true)) {
            foreach ($approvers as $approver) {
                if (! filter_var((string) $approver->email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                Mail::to((string) $approver->email)->queue(new PasswordRecoveryRequestNotificationMail(
                    requesterName: (string) $target->name,
                    requesterEmail: (string) $target->email,
                    tenantName: $tenantName,
                    requesterRole: $roleLabel,
                    requestedAt: $requestedAt,
                    requestId: $request->id,
                ));
            }
        }

        $this->publishApproverInAppNotification(
            $request,
            $target,
            $approvers->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            $tenantName,
            $roleLabel,
        );
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveApproverRecipients(PasswordRecoveryRequest $request): Collection
    {
        if (in_array($request->requester_classification, [
            PasswordRecoveryRequest::CLASSIFICATION_EKKLESIA_USER,
            PasswordRecoveryRequest::CLASSIFICATION_TENANT_ADMIN,
        ], true)) {
            return $this->approverResolver->resolveSuperAdmins();
        }

        if ($request->requester_classification === PasswordRecoveryRequest::CLASSIFICATION_TENANT_USER
            && $request->intended_approver_user_id !== null
        ) {
            $approver = User::query()->find($request->intended_approver_user_id);

            return $approver !== null ? collect([$approver]) : collect();
        }

        return collect();
    }

    /**
     * @param  list<int>  $recipientIds
     */
    private function publishApproverInAppNotification(
        PasswordRecoveryRequest $request,
        User $target,
        array $recipientIds,
        ?string $tenantName,
        string $roleLabel,
    ): void {
        if ($recipientIds === [] || ! interface_exists(\Modules\Notifications\Contracts\NotificationPublisherContract::class)) {
            return;
        }

        $isTenantScoped = $request->requester_classification === PasswordRecoveryRequest::CLASSIFICATION_TENANT_USER
            && $request->tenant_id !== null;

        try {
            app(\Modules\Notifications\Contracts\NotificationPublisherContract::class)->publish(
                new \Modules\Notifications\Support\NotificationIntent(
                    definitionCode: 'auth.password_recovery.requested',
                    actor: $target,
                    subjectType: 'password_recovery_request',
                    subjectId: (string) $request->id,
                    tenantId: $isTenantScoped ? (int) $request->tenant_id : null,
                    scope: $isTenantScoped
                        ? \Modules\Notifications\Support\InboxScope::Tenant
                        : \Modules\Notifications\Support\InboxScope::Platform,
                    occurrenceId: (string) $request->id,
                    data: [
                        'requester_name' => (string) $target->name,
                        'requester_email' => (string) $target->email,
                        'requester_role' => $roleLabel,
                        'tenant_name' => $tenantName ?? 'Platform',
                    ],
                    explicitRecipientIds: $recipientIds,
                    actionStatus: 'required',
                )
            );
        } catch (Throwable $e) {
            Log::warning('Password recovery in-app notification failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function completeApproverNotificationSubject(string $requestId): void
    {
        if (! interface_exists(\Modules\Notifications\Contracts\NotificationPublisherContract::class)) {
            return;
        }

        try {
            app(\Modules\Notifications\Contracts\NotificationPublisherContract::class)
                ->completeSubject('password_recovery_request', $requestId);
        } catch (Throwable $e) {
            Log::warning('Password recovery notification subject completion failed', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function hasInflightRequest(int $userId): bool
    {
        return PasswordRecoveryRequest::query()
            ->where('user_id', $userId)
            ->whereIn('status', [
                PasswordRecoveryRequest::STATUS_PENDING_APPROVAL,
                PasswordRecoveryRequest::STATUS_PROCESSING,
            ])
            ->exists();
    }

    private function findRequestOrFail(string $requestId): PasswordRecoveryRequest
    {
        return PasswordRecoveryRequest::query()->whereKey($requestId)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function auditApproval(string $event, User $actor, PasswordRecoveryRequest $request, array $extra = []): void
    {
        $target = User::query()->find($request->user_id);
        $context = app(TenantContext::class);

        $this->audit->recordWithActor($event, $actor, $target, array_merge([
            'request_id' => $request->id,
            'status' => $request->status,
            'support_session_id' => $context->supportSessionId(),
        ], $extra));
    }

    private function roleLabelForClassification(string $classification): string
    {
        return match ($classification) {
            PasswordRecoveryRequest::CLASSIFICATION_SUPER_ADMIN => 'Super Admin',
            PasswordRecoveryRequest::CLASSIFICATION_EKKLESIA_USER => 'Ekklesia User',
            PasswordRecoveryRequest::CLASSIFICATION_TENANT_ADMIN => 'Church Administrator',
            PasswordRecoveryRequest::CLASSIFICATION_TENANT_USER => 'Church User',
            default => 'User',
        };
    }
}
