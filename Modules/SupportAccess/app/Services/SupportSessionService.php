<?php

namespace Modules\SupportAccess\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\SupportAccess\Models\SupportSession;
use Modules\SupportAccess\Models\SupportSessionEvent;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\SupportSessionMode;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportSessionService
{
    /** @var list<string> */
    public const REASON_CODES = [
        'diagnosis',
        'data_fix',
        'configuration',
        'training',
        'incident',
        'other',
    ];

    public function __construct(
        private readonly SupportSettingsService $settings,
        private readonly SupportSessionNotificationPublisher $notifications,
        private readonly SupportApprovalService $approvals,
        private readonly SupportGrantService $grants,
        private readonly SupportTicketValidationService $tickets,
    ) {
    }

    /**
     * @param  array{tenant_id:int,mode:string,reason_code:string,reason_description?:string|null,ticket_ref?:string|null,password:string,confirm_emergency?:bool,approval_request_id?:string|null}  $payload
     */
    public function start(Authenticatable $actor, array $payload, ?string $ip, ?string $userAgent): SupportSession
    {
        if (! Hash::check($payload['password'], (string) $actor->password)) {
            throw new RuntimeException('Password confirmation failed.');
        }

        $mode = SupportSessionMode::tryFrom($payload['mode']);
        if ($mode === null) {
            throw new RuntimeException('Unsupported support session mode.');
        }

        if ($mode === SupportSessionMode::Emergency) {
            if (empty($payload['confirm_emergency'])) {
                throw new RuntimeException('Emergency mode requires explicit double confirmation.');
            }
        }

        $modePermission = match ($mode) {
            SupportSessionMode::Readonly => 'support.sessions.readonly',
            SupportSessionMode::Standard => 'support.sessions.standard',
            SupportSessionMode::Emergency => 'support.sessions.emergency',
        };

        if (! $this->actorHasPermission($actor, 'support.sessions.start')
            || ! $this->actorHasPermission($actor, $modePermission)) {
            throw new RuntimeException('Missing support session permission.');
        }

        $this->tickets->assertValid($payload['ticket_ref'] ?? null);

        $ops = $this->settings->getOpsSettings();
        $this->assertRateLimit((int) $actor->getAuthIdentifier(), $ops['start_rate_limit_per_hour']);

        $tenant = Tenant::query()->find($payload['tenant_id']);
        if (! $tenant || ! $tenant->active) {
            throw new RuntimeException('Target tenant is not available for support.');
        }

        $this->expireStaleSessions();

        $actorId = (int) $actor->getAuthIdentifier();
        $ownActive = SupportSession::query()
            ->where('support_user_id', $actorId)
            ->where('status', SupportSession::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->count();

        if ($ownActive >= $ops['max_sessions_per_user']) {
            // Replace oldest own active sessions beyond the allowed per-user count.
            $toEnd = SupportSession::query()
                ->where('support_user_id', $actorId)
                ->where('status', SupportSession::STATUS_ACTIVE)
                ->where('expires_at', '>', now())
                ->orderBy('started_at')
                ->limit(max(1, $ownActive - $ops['max_sessions_per_user'] + 1))
                ->get();

            foreach ($toEnd as $existing) {
                $this->closeSession($existing, $actorId, 'replaced');
            }
        }

        $globalActive = SupportSession::query()
            ->where('status', SupportSession::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->count();

        if ($globalActive >= $ops['max_concurrent_sessions']) {
            throw new RuntimeException('Maximum concurrent support sessions reached. End an active session and try again.');
        }

        return DB::transaction(function () use ($actor, $payload, $mode, $tenant, $ip, $userAgent, $ops, $actorId) {
            $grant = $this->grants->findActiveCovering((int) $tenant->id, $mode);
            if (($ops['require_customer_grant'] ?? false) && $grant === null) {
                throw new RuntimeException('An active customer access grant is required to start a support session for this tenant.');
            }

            $approval = null;
            if ($mode === SupportSessionMode::Emergency && ($ops['emergency_requires_approval'] ?? true)) {
                $approvalId = $payload['approval_request_id'] ?? null;
                if (! is_string($approvalId) || $approvalId === '') {
                    throw new RuntimeException('Emergency mode requires an approved access request.');
                }
                $approval = $this->approvals->claimForSessionStart(
                    $actor,
                    $approvalId,
                    (int) $tenant->id,
                    $mode,
                );
            }

            $timeoutMinutes = (int) $ops['timeout_minutes'];
            $usedJit = false;
            if (($ops['jit_enabled'] ?? false)
                && ($mode === SupportSessionMode::Standard || $mode === SupportSessionMode::Emergency)) {
                $jit = (int) ($ops['jit_timeout_minutes'] ?? 15);
                $timeoutMinutes = min($timeoutMinutes, max(5, $jit));
                $usedJit = true;
            }

            $expiresAt = now()->addMinutes($timeoutMinutes);
            if ($grant !== null && $grant->ends_at !== null && $grant->ends_at->lt($expiresAt)) {
                $expiresAt = $grant->ends_at->copy();
            }

            if ($expiresAt->lte(now())) {
                throw new RuntimeException('Access window has already ended; cannot start a support session.');
            }

            $session = SupportSession::query()->create([
                'id' => (string) Str::uuid(),
                'support_user_id' => $actorId,
                'tenant_id' => $tenant->id,
                'mode' => $mode->value,
                'reason_code' => $payload['reason_code'],
                'reason_description' => $payload['reason_description'] ?? null,
                'ticket_ref' => $payload['ticket_ref'] ?? null,
                'status' => SupportSession::STATUS_ACTIVE,
                'started_at' => now(),
                'expires_at' => $expiresAt,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'approval_request_id' => $approval?->id,
                'access_grant_id' => $grant?->id,
            ]);

            if ($approval !== null) {
                $this->approvals->markConsumed($approval, $session->id);
            }

            if ($grant !== null) {
                $this->grants->incrementUsage($grant);
            }

            SupportSessionEvent::query()->create([
                'support_session_id' => $session->id,
                'actor_user_id' => $actorId,
                'effective_tenant_id' => $tenant->id,
                'event_type' => 'session_started',
                'module' => 'SupportAccess',
                'page' => 'support-center',
                'action' => 'start',
                'metadata' => [
                    'mode' => $mode->value,
                    'reason_code' => $payload['reason_code'],
                    'timeout_minutes' => $timeoutMinutes,
                    'jit_applied' => $usedJit,
                    'emergency_confirmed' => $mode === SupportSessionMode::Emergency,
                    'approval_request_id' => $approval?->id,
                    'access_grant_id' => $grant?->id,
                ],
                'created_at' => now(),
            ]);

            $this->bumpRateLimit($actorId);

            $loaded = $session->load([
                'tenant:id,name,slug,tenant_tier,active',
                'supportUser:id,name,email',
            ]);

            DB::afterCommit(function () use ($loaded): void {
                Log::info('support_access.session.started', [
                    'support_session_id' => $loaded->id,
                    'actor_user_id' => $loaded->support_user_id,
                    'effective_tenant_id' => $loaded->tenant_id,
                    'mode' => $loaded->mode,
                ]);
                $this->safeNotify(fn () => $this->notifications->sessionStarted($loaded));
            });

            return $loaded;
        });
    }

    public function end(Authenticatable $actor, string $sessionId, string $reason = 'manual'): SupportSession
    {
        /** @var SupportSession $session */
        $session = SupportSession::query()->findOrFail($sessionId);
        $actorId = (int) $actor->getAuthIdentifier();

        $isOwner = (int) $session->support_user_id === $actorId;
        if (! $isOwner && ! $this->actorHasPermission($actor, 'support.sessions.end')) {
            throw new RuntimeException('Not allowed to end this support session.');
        }

        if ($session->status !== SupportSession::STATUS_ACTIVE) {
            return $session->load([
                'tenant:id,name,slug,tenant_tier,active',
                'supportUser:id,name,email',
            ]);
        }

        return $this->closeSession($session, $actorId, $isOwner ? $reason : 'force_ended');
    }

    /**
     * @param  array{password:string}  $payload
     */
    public function renew(Authenticatable $actor, string $sessionId, array $payload, ?string $ip = null): SupportSession
    {
        if (! Hash::check($payload['password'], (string) $actor->password)) {
            throw new RuntimeException('Password confirmation failed.');
        }

        /** @var SupportSession $session */
        $session = SupportSession::query()->findOrFail($sessionId);
        $actorId = (int) $actor->getAuthIdentifier();

        if ((int) $session->support_user_id !== $actorId) {
            throw new RuntimeException('Not allowed to renew this support session.');
        }

        if (! $session->isActive() || $session->expires_at?->isPast()) {
            throw new RuntimeException('Support session is not active.');
        }

        $ops = $this->settings->getOpsSettings();
        $timeoutMinutes = (int) $ops['timeout_minutes'];
        $mode = SupportSessionMode::tryFrom((string) $session->mode);
        if (($ops['jit_enabled'] ?? false)
            && ($mode === SupportSessionMode::Standard || $mode === SupportSessionMode::Emergency)) {
            $jit = (int) ($ops['jit_timeout_minutes'] ?? 15);
            $timeoutMinutes = min($timeoutMinutes, max(5, $jit));
        }

        $expiresAt = now()->addMinutes($timeoutMinutes);

        if ($session->access_grant_id) {
            $grant = \Modules\SupportAccess\Models\SupportAccessGrant::query()->find($session->access_grant_id);
            if ($grant !== null && $grant->ends_at !== null && $grant->ends_at->lt($expiresAt)) {
                $expiresAt = $grant->ends_at->copy();
            }
        }

        if ($expiresAt->lte(now())) {
            throw new RuntimeException('Access window has already ended; cannot renew this support session.');
        }

        $session->expires_at = $expiresAt;
        $session->save();

        SupportSessionEvent::query()->create([
            'support_session_id' => $session->id,
            'actor_user_id' => $actorId,
            'effective_tenant_id' => $session->tenant_id,
            'event_type' => 'session_renewed',
            'module' => 'SupportAccess',
            'page' => 'support-center',
            'action' => 'renew',
            'metadata' => [
                'timeout_minutes' => $timeoutMinutes,
                'expires_at' => $expiresAt->toIso8601String(),
                'request_ip' => $ip,
            ],
            'created_at' => now(),
        ]);

        Log::info('support_access.session.renewed', [
            'support_session_id' => $session->id,
            'actor_user_id' => $actorId,
            'effective_tenant_id' => $session->tenant_id,
            'mode' => $session->mode,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return $session->load([
            'tenant:id,name,slug,tenant_tier,active',
            'supportUser:id,name,email',
        ]);
    }

    /**
     * @return array{active_count:int,pending_approvals:int,expiring_soon_count:int,expiring_within_minutes:int}
     */
    public function metrics(): array
    {
        $this->expireStaleSessions();

        $expiringWithin = 15;
        $activeQuery = SupportSession::query()
            ->where('status', SupportSession::STATUS_ACTIVE)
            ->where('expires_at', '>', now());

        return [
            'active_count' => (clone $activeQuery)->count(),
            'pending_approvals' => \Modules\SupportAccess\Models\SupportAccessRequest::query()
                ->where('status', \Modules\SupportAccess\Models\SupportAccessRequest::STATUS_PENDING)
                ->where('expires_at', '>', now())
                ->count(),
            'expiring_soon_count' => (clone $activeQuery)
                ->where('expires_at', '<=', now()->addMinutes($expiringWithin))
                ->count(),
            'expiring_within_minutes' => $expiringWithin,
        ];
    }

    public function findForActor(Authenticatable $actor, string $sessionId): SupportSession
    {
        /** @var SupportSession $session */
        $session = SupportSession::query()
            ->with([
                'tenant:id,name,slug,tenant_tier,active',
                'supportUser:id,name,email',
            ])
            ->findOrFail($sessionId);

        $isOwner = (int) $session->support_user_id === (int) $actor->getAuthIdentifier();
        if (! $isOwner && ! $this->actorHasPermission($actor, 'support.sessions.view')) {
            throw new RuntimeException('Support session not found for this user.');
        }

        return $session;
    }

    public function listForActor(Authenticatable $actor, int $perPage = 20): LengthAwarePaginator
    {
        return SupportSession::query()
            ->with(['tenant:id,name,slug,tenant_tier,active'])
            ->where('support_user_id', $actor->getAuthIdentifier())
            ->orderByDesc('started_at')
            ->paginate($perPage);
    }

    public function activeForActor(Authenticatable $actor): ?SupportSession
    {
        $this->expireStaleSessions();

        /** @var SupportSession|null $session */
        $session = SupportSession::query()
            ->with(['tenant:id,name,slug,tenant_tier,active'])
            ->where('support_user_id', $actor->getAuthIdentifier())
            ->where('status', SupportSession::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->orderByDesc('started_at')
            ->first();

        return $session;
    }

    /**
     * Platform monitor: all currently active sessions.
     */
    public function listActiveGlobal(int $perPage = 50): LengthAwarePaginator
    {
        $this->expireStaleSessions();

        return SupportSession::query()
            ->with([
                'tenant:id,name,slug,tenant_tier,active',
                'supportUser:id,name,email',
            ])
            ->where('status', SupportSession::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->orderByDesc('started_at')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function searchHistory(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $this->expireStaleSessions();

        return $this->historyQuery($filters)
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function exportHistoryCsv(array $filters): StreamedResponse
    {
        $this->expireStaleSessions();
        $query = $this->historyQuery($filters)->limit(5000);

        $filename = 'support-sessions-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, [
                'session_id',
                'support_user_id',
                'support_user_email',
                'tenant_id',
                'tenant_name',
                'mode',
                'status',
                'reason_code',
                'ticket_ref',
                'started_at',
                'expires_at',
                'ended_at',
                'ended_reason',
                'ip_address',
            ]);

            $query->chunk(200, function ($rows) use ($out): void {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->id,
                        $row->support_user_id,
                        $row->supportUser?->email,
                        $row->tenant_id,
                        $row->tenant?->name,
                        $row->mode,
                        $row->status,
                        $row->reason_code,
                        $row->ticket_ref,
                        optional($row->started_at)?->toIso8601String(),
                        optional($row->expires_at)?->toIso8601String(),
                        optional($row->ended_at)?->toIso8601String(),
                        $row->ended_reason,
                        $row->ip_address,
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function expireStaleSessions(): int
    {
        $stale = SupportSession::query()
            ->where('status', SupportSession::STATUS_ACTIVE)
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($stale as $session) {
            $session->status = SupportSession::STATUS_EXPIRED;
            $session->ended_at = now();
            $session->ended_reason = 'timeout';
            $session->save();

            SupportSessionEvent::query()->create([
                'support_session_id' => $session->id,
                'actor_user_id' => $session->support_user_id,
                'effective_tenant_id' => $session->tenant_id,
                'event_type' => 'session_timeout',
                'module' => 'SupportAccess',
                'action' => 'timeout',
                'created_at' => now(),
            ]);

            $loaded = $session->load([
                'tenant:id,name,slug,tenant_tier,active',
                'supportUser:id,name,email',
            ]);

            Log::info('support_access.session.expired', [
                'support_session_id' => $session->id,
                'actor_user_id' => $session->support_user_id,
                'effective_tenant_id' => $session->tenant_id,
                'mode' => $session->mode,
            ]);

            $this->safeNotify(fn () => $this->notifications->sessionEnded($loaded));
        }

        return $stale->count();
    }

    /**
     * Record a breadcrumb / activity event for an active support session.
     *
     * @param  array{event_type?:string,module?:string,page?:string,entity_type?:string,entity_id?:string,action?:string,metadata?:array<string,mixed>}  $payload
     */
    public function recordEvent(Authenticatable $actor, string $sessionId, array $payload): SupportSessionEvent
    {
        /** @var SupportSession $session */
        $session = SupportSession::query()->findOrFail($sessionId);
        $actorId = (int) $actor->getAuthIdentifier();

        if ((int) $session->support_user_id !== $actorId) {
            throw new RuntimeException('Support session not found for this user.');
        }

        if (! $session->isActive()) {
            throw new RuntimeException('Support session is not active.');
        }

        return SupportSessionEvent::query()->create([
            'support_session_id' => $session->id,
            'actor_user_id' => $actorId,
            'effective_tenant_id' => $session->tenant_id,
            'event_type' => $payload['event_type'] ?? 'page_view',
            'module' => $payload['module'] ?? null,
            'page' => $payload['page'] ?? null,
            'entity_type' => $payload['entity_type'] ?? null,
            'entity_id' => $payload['entity_id'] ?? null,
            'action' => $payload['action'] ?? 'view',
            'metadata' => $payload['metadata'] ?? null,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function searchEvents(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $query = SupportSessionEvent::query()
            ->with([
                'session:id,mode,status,tenant_id,support_user_id',
                'actor:id,name,email',
                'tenant:id,name,slug',
            ])
            ->orderByDesc('created_at');

        if (! empty($filters['support_session_id'])) {
            $query->where('support_session_id', $filters['support_session_id']);
        }
        if (! empty($filters['effective_tenant_id'])) {
            $query->where('effective_tenant_id', (int) $filters['effective_tenant_id']);
        }
        if (! empty($filters['actor_user_id'])) {
            $query->where('actor_user_id', (int) $filters['actor_user_id']);
        }
        if (! empty($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }
        if (! empty($filters['module'])) {
            $query->where('module', $filters['module']);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }
        if (! empty($filters['q'])) {
            $q = '%'.trim((string) $filters['q']).'%';
            $query->where(function (Builder $builder) use ($q): void {
                $builder->where('page', 'ilike', $q)
                    ->orWhere('module', 'ilike', $q)
                    ->orWhere('action', 'ilike', $q)
                    ->orWhere('entity_type', 'ilike', $q)
                    ->orWhere('entity_id', 'ilike', $q);
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function exportEventsCsv(array $filters): StreamedResponse
    {
        $query = SupportSessionEvent::query()
            ->with(['actor:id,name,email', 'tenant:id,name'])
            ->orderByDesc('created_at')
            ->limit(10000);

        if (! empty($filters['support_session_id'])) {
            $query->where('support_session_id', $filters['support_session_id']);
        }
        if (! empty($filters['effective_tenant_id'])) {
            $query->where('effective_tenant_id', (int) $filters['effective_tenant_id']);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }
        if (! empty($filters['q'])) {
            $q = '%'.trim((string) $filters['q']).'%';
            $query->where(function (Builder $builder) use ($q): void {
                $builder->where('page', 'ilike', $q)
                    ->orWhere('module', 'ilike', $q)
                    ->orWhere('action', 'ilike', $q);
            });
        }

        $filename = 'support-events-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, [
                'event_id',
                'support_session_id',
                'actor_user_id',
                'actor_email',
                'effective_tenant_id',
                'tenant_name',
                'event_type',
                'module',
                'page',
                'entity_type',
                'entity_id',
                'action',
                'created_at',
            ]);

            $query->chunk(200, function ($rows) use ($out): void {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->id,
                        $row->support_session_id,
                        $row->actor_user_id,
                        $row->actor?->email,
                        $row->effective_tenant_id,
                        $row->tenant?->name,
                        $row->event_type,
                        $row->module,
                        $row->page,
                        $row->entity_type,
                        $row->entity_id,
                        $row->action,
                        optional($row->created_at)?->toIso8601String(),
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function historyQuery(array $filters): Builder
    {
        $query = SupportSession::query()
            ->with([
                'tenant:id,name,slug,tenant_tier,active',
                'supportUser:id,name,email',
            ])
            ->orderByDesc('started_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['mode'])) {
            $query->where('mode', $filters['mode']);
        }

        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', (int) $filters['tenant_id']);
        }

        if (! empty($filters['support_user_id'])) {
            $query->where('support_user_id', (int) $filters['support_user_id']);
        }

        if (! empty($filters['from'])) {
            $query->where('started_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('started_at', '<=', $filters['to']);
        }

        if (! empty($filters['q'])) {
            $q = '%'.trim((string) $filters['q']).'%';
            $query->where(function (Builder $builder) use ($q): void {
                $builder->where('reason_code', 'ilike', $q)
                    ->orWhere('ticket_ref', 'ilike', $q)
                    ->orWhere('reason_description', 'ilike', $q)
                    ->orWhereHas('tenant', function (Builder $tenantQuery) use ($q): void {
                        $tenantQuery->where('name', 'ilike', $q)
                            ->orWhere('slug', 'ilike', $q);
                    })
                    ->orWhereHas('supportUser', function (Builder $userQuery) use ($q): void {
                        $userQuery->where('email', 'ilike', $q)
                            ->orWhere('name', 'ilike', $q);
                    });
            });
        }

        return $query;
    }

    private function closeSession(SupportSession $session, int $actorUserId, string $reason): SupportSession
    {
        $session->status = $reason === 'timeout'
            ? SupportSession::STATUS_EXPIRED
            : SupportSession::STATUS_ENDED;
        $session->ended_at = now();
        $session->ended_reason = $reason;
        $session->save();

        SupportSessionEvent::query()->create([
            'support_session_id' => $session->id,
            'actor_user_id' => $actorUserId,
            'effective_tenant_id' => $session->tenant_id,
            'event_type' => $reason === 'force_ended' ? 'session_force_ended' : 'session_ended',
            'module' => 'SupportAccess',
            'page' => 'support-center',
            'action' => $reason === 'force_ended' ? 'force_end' : 'end',
            'metadata' => ['reason' => $reason],
            'created_at' => now(),
        ]);

        $loaded = $session->load([
            'tenant:id,name,slug,tenant_tier,active',
            'supportUser:id,name,email',
        ]);

        $this->safeNotify(fn () => $this->notifications->sessionEnded($loaded));

        Log::info('support_access.session.ended', [
            'support_session_id' => $session->id,
            'actor_user_id' => $actorUserId,
            'effective_tenant_id' => $session->tenant_id,
            'mode' => $session->mode,
            'ended_reason' => $reason,
        ]);

        return $loaded;
    }

    /**
     * Notification failures must never block session lifecycle.
     *
     * @param  callable():void  $callback
     */
    private function safeNotify(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::error('support_access.notification.dispatch_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function assertRateLimit(int $actorId, int $perHour): void
    {
        $key = $this->rateLimitKey($actorId);
        $count = (int) Cache::get($key, 0);
        if ($count >= $perHour) {
            throw new RuntimeException('Support session start rate limit exceeded. Try again later.');
        }
    }

    private function bumpRateLimit(int $actorId): void
    {
        $key = $this->rateLimitKey($actorId);
        if (! Cache::has($key)) {
            Cache::put($key, 1, now()->addHour());

            return;
        }

        Cache::increment($key);
    }

    private function rateLimitKey(int $actorId): string
    {
        return 'support_session_start:'.$actorId;
    }

    private function actorHasPermission(Authenticatable $actor, string $permission): bool
    {
        if (method_exists($actor, 'isSuperAdmin') && $actor->isSuperAdmin()) {
            return true;
        }

        return method_exists($actor, 'hasPermission') && $actor->hasPermission($permission);
    }
}
