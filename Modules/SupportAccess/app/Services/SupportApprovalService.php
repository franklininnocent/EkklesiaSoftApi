<?php

namespace Modules\SupportAccess\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\SupportAccess\Models\SupportAccessRequest;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\SupportSessionMode;
use RuntimeException;

class SupportApprovalService
{
    public function __construct(
        private readonly SupportSettingsService $settings,
        private readonly SupportTicketValidationService $tickets,
        private readonly SupportSessionNotificationPublisher $notifications,
    ) {
    }

    /**
     * @param  array{tenant_id:int,mode?:string,reason_code:string,reason_description?:string|null,ticket_ref?:string|null}  $payload
     */
    public function request(Authenticatable $actor, array $payload): SupportAccessRequest
    {
        if (! $this->actorHasPermission($actor, 'support.sessions.start')
            || ! $this->actorHasPermission($actor, 'support.sessions.emergency')) {
            throw new RuntimeException('Missing permission to request emergency access.');
        }

        $mode = SupportSessionMode::tryFrom($payload['mode'] ?? SupportSessionMode::Emergency->value);
        if ($mode !== SupportSessionMode::Emergency) {
            throw new RuntimeException('Approval requests are only supported for emergency mode.');
        }

        $this->tickets->assertValid($payload['ticket_ref'] ?? null);

        $tenant = Tenant::query()->find($payload['tenant_id']);
        if (! $tenant || ! $tenant->active) {
            throw new RuntimeException('Target tenant is not available for support.');
        }

        $this->expireStale();

        $ops = $this->settings->getOpsSettings();
        $ttl = (int) ($ops['approval_request_ttl_minutes'] ?? 60);

        $request = SupportAccessRequest::query()->create([
            'id' => (string) Str::uuid(),
            'requester_user_id' => (int) $actor->getAuthIdentifier(),
            'tenant_id' => $tenant->id,
            'mode' => $mode->value,
            'reason_code' => $payload['reason_code'],
            'reason_description' => $payload['reason_description'] ?? null,
            'ticket_ref' => $payload['ticket_ref'] ?? null,
            'status' => SupportAccessRequest::STATUS_PENDING,
            'requested_at' => now(),
            'expires_at' => now()->addMinutes(max(5, $ttl)),
        ]);

        $loaded = $request->load([
            'tenant:id,name,slug,tenant_tier,active',
            'requester:id,name,email',
        ]);

        try {
            $this->notifications->approvalRequested($loaded);
        } catch (\Throwable) {
            // never block approval create
        }

        return $loaded;
    }

    public function approve(Authenticatable $actor, string $requestId, ?string $note = null): SupportAccessRequest
    {
        if (! $this->actorHasPermission($actor, 'support.sessions.approve')) {
            throw new RuntimeException('Missing permission to approve emergency access.');
        }

        $this->expireStale();

        /** @var SupportAccessRequest $request */
        $request = SupportAccessRequest::query()->findOrFail($requestId);

        if ((int) $request->requester_user_id === (int) $actor->getAuthIdentifier()) {
            throw new RuntimeException('Four-eyes rule: you cannot approve your own emergency request.');
        }

        if (! $request->isPendingUsable()) {
            throw new RuntimeException('This approval request is not pending or has expired.');
        }

        $request->status = SupportAccessRequest::STATUS_APPROVED;
        $request->decided_by_user_id = (int) $actor->getAuthIdentifier();
        $request->decided_at = now();
        $request->decision_note = $note;
        // Approved requests keep remaining TTL from original expires_at.
        $request->save();

        return $request->load([
            'tenant:id,name,slug,tenant_tier,active',
            'requester:id,name,email',
            'decidedBy:id,name,email',
        ]);
    }

    public function reject(Authenticatable $actor, string $requestId, ?string $note = null): SupportAccessRequest
    {
        if (! $this->actorHasPermission($actor, 'support.sessions.approve')) {
            throw new RuntimeException('Missing permission to reject emergency access.');
        }

        /** @var SupportAccessRequest $request */
        $request = SupportAccessRequest::query()->findOrFail($requestId);

        if ((int) $request->requester_user_id === (int) $actor->getAuthIdentifier()) {
            throw new RuntimeException('Four-eyes rule: you cannot reject your own emergency request.');
        }

        if ($request->status !== SupportAccessRequest::STATUS_PENDING) {
            throw new RuntimeException('Only pending requests can be rejected.');
        }

        $request->status = SupportAccessRequest::STATUS_REJECTED;
        $request->decided_by_user_id = (int) $actor->getAuthIdentifier();
        $request->decided_at = now();
        $request->decision_note = $note;
        $request->save();

        return $request->load([
            'tenant:id,name,slug,tenant_tier,active',
            'requester:id,name,email',
            'decidedBy:id,name,email',
        ]);
    }

    public function cancel(Authenticatable $actor, string $requestId): SupportAccessRequest
    {
        /** @var SupportAccessRequest $request */
        $request = SupportAccessRequest::query()->findOrFail($requestId);
        $actorId = (int) $actor->getAuthIdentifier();

        $isOwner = (int) $request->requester_user_id === $actorId;
        if (! $isOwner && ! $this->actorHasPermission($actor, 'support.sessions.approve')) {
            throw new RuntimeException('Not allowed to cancel this approval request.');
        }

        if (! in_array($request->status, [
            SupportAccessRequest::STATUS_PENDING,
            SupportAccessRequest::STATUS_APPROVED,
        ], true)) {
            throw new RuntimeException('Only pending or approved requests can be cancelled.');
        }

        $request->status = SupportAccessRequest::STATUS_CANCELLED;
        $request->save();

        return $request->load([
            'tenant:id,name,slug,tenant_tier,active',
            'requester:id,name,email',
            'decidedBy:id,name,email',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, int $perPage = 30): LengthAwarePaginator
    {
        $this->expireStale();

        $query = SupportAccessRequest::query()
            ->with([
                'tenant:id,name,slug,tenant_tier,active',
                'requester:id,name,email',
                'decidedBy:id,name,email',
            ])
            ->orderByDesc('requested_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', (int) $filters['tenant_id']);
        }

        if (! empty($filters['requester_user_id'])) {
            $query->where('requester_user_id', (int) $filters['requester_user_id']);
        }

        if (! empty($filters['mine_for'])) {
            $actorId = (int) $filters['mine_for'];
            $query->where(function (Builder $q) use ($actorId): void {
                $q->where('requester_user_id', $actorId)
                    ->orWhere('status', SupportAccessRequest::STATUS_PENDING);
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Validate and lock an approved request for consumption by session start.
     */
    public function claimForSessionStart(
        Authenticatable $actor,
        string $requestId,
        int $tenantId,
        SupportSessionMode $mode,
    ): SupportAccessRequest {
        $this->expireStale();

        /** @var SupportAccessRequest $request */
        $request = SupportAccessRequest::query()
            ->whereKey($requestId)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $request->requester_user_id !== (int) $actor->getAuthIdentifier()) {
            throw new RuntimeException('Emergency approval request belongs to another support user.');
        }

        if ((int) $request->tenant_id !== $tenantId) {
            throw new RuntimeException('Emergency approval request does not match the selected tenant.');
        }

        if ($request->mode !== $mode->value) {
            throw new RuntimeException('Emergency approval request does not match the selected mode.');
        }

        if (! $request->isApprovedUsable()) {
            throw new RuntimeException('Emergency approval request is not approved or has expired.');
        }

        return $request;
    }

    public function markConsumed(SupportAccessRequest $request, string $sessionId): void
    {
        $request->status = SupportAccessRequest::STATUS_CONSUMED;
        $request->consumed_session_id = $sessionId;
        $request->save();
    }

    public function expireStale(): void
    {
        SupportAccessRequest::query()
            ->whereIn('status', [
                SupportAccessRequest::STATUS_PENDING,
                SupportAccessRequest::STATUS_APPROVED,
            ])
            ->where('expires_at', '<=', now())
            ->update(['status' => SupportAccessRequest::STATUS_EXPIRED]);
    }

    private function actorHasPermission(Authenticatable $actor, string $permission): bool
    {
        if (method_exists($actor, 'isSuperAdmin') && $actor->isSuperAdmin()) {
            return true;
        }

        return method_exists($actor, 'hasPermission') && $actor->hasPermission($permission);
    }
}
