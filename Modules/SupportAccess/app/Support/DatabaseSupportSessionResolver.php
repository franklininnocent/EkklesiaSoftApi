<?php

namespace Modules\SupportAccess\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\SupportAccess\Models\SupportSession;
use Modules\SupportAccess\Models\SupportSessionEvent;
use Modules\SupportAccess\Services\SupportSettingsService;
use Modules\Tenants\Contracts\SupportSessionResolver;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\ActiveSupportSession;
use Modules\Tenants\Support\SupportSessionMode;

final class DatabaseSupportSessionResolver implements SupportSessionResolver
{
    public const HEADER = 'X-Support-Session-Id';

    public function resolve(Request $request, Authenticatable $actor): ?ActiveSupportSession
    {
        $sessionId = $request->headers->get(self::HEADER);
        if (! $sessionId) {
            return null;
        }

        /** @var SupportSession|null $session */
        $session = SupportSession::query()->find($sessionId);
        if (! $session) {
            abort(response()->json([
                'success' => false,
                'message' => 'Invalid support session.',
            ], 403));
        }

        if ((int) $session->support_user_id !== (int) $actor->getAuthIdentifier()) {
            abort(response()->json([
                'success' => false,
                'message' => 'Support session does not belong to this user.',
            ], 403));
        }

        if ($session->status === SupportSession::STATUS_ACTIVE && $session->expires_at?->isPast()) {
            $this->markExpired($session, 'timeout', 'session_timeout');
        }

        if (! $session->isActive()) {
            abort(response()->json([
                'success' => false,
                'message' => 'Support session is no longer active.',
            ], 403));
        }

        $tenant = Tenant::query()->find($session->tenant_id);
        if (! $tenant || ! $tenant->active) {
            $this->markExpired($session, 'tenant_unavailable', 'session_ended');
            abort(response()->json([
                'success' => false,
                'message' => 'Target tenant is no longer available for support.',
            ], 403));
        }

        $this->assertIpBinding($request, $session);

        $mode = SupportSessionMode::tryFrom((string) $session->mode);
        if ($mode === null) {
            abort(response()->json([
                'success' => false,
                'message' => 'Support session mode is invalid.',
            ], 403));
        }

        return new ActiveSupportSession(
            id: (string) $session->id,
            tenantId: (int) $session->tenant_id,
            mode: $mode,
            supportUserId: (int) $session->support_user_id,
            expiresAt: $session->expires_at,
            reasonCode: (string) $session->reason_code,
        );
    }

    private function markExpired(SupportSession $session, string $reason, string $eventType): void
    {
        if ($session->status !== SupportSession::STATUS_ACTIVE) {
            return;
        }

        $session->status = $reason === 'timeout'
            ? SupportSession::STATUS_EXPIRED
            : SupportSession::STATUS_ENDED;
        $session->ended_at = now();
        $session->ended_reason = $reason;
        $session->save();

        SupportSessionEvent::query()->create([
            'support_session_id' => $session->id,
            'actor_user_id' => $session->support_user_id,
            'effective_tenant_id' => $session->tenant_id,
            'event_type' => $eventType,
            'module' => 'SupportAccess',
            'action' => $reason,
            'metadata' => ['reason' => $reason],
            'created_at' => now(),
        ]);
    }

    private function assertIpBinding(Request $request, SupportSession $session): void
    {
        try {
            $mode = app(SupportSettingsService::class)->getOpsSettings()['ip_binding_mode'] ?? 'soft';
        } catch (\Throwable) {
            $mode = 'soft';
        }

        if ($mode === 'off' || empty($session->ip_address)) {
            return;
        }

        $current = (string) $request->ip();
        if ($current === '' || $current === (string) $session->ip_address) {
            return;
        }

        Log::warning('support_access.session.ip_mismatch', [
            'support_session_id' => $session->id,
            'actor_user_id' => $session->support_user_id,
            'effective_tenant_id' => $session->tenant_id,
            'started_ip' => $session->ip_address,
            'request_ip' => $current,
            'binding_mode' => $mode,
        ]);

        if ($mode === 'strict') {
            abort(response()->json([
                'success' => false,
                'message' => 'Support session IP binding failed.',
            ], 403));
        }
    }
}
