<?php

namespace Modules\ApplicationAccess\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\ApplicationAccess\Http\Middleware\AssignRequestId;
use Modules\Authentication\Models\User;
use Symfony\Component\HttpFoundation\Response;

class ApplicationAccessThreatWriter
{
    public function __construct(
        private readonly ApplicationAccessRecorder $recorder,
        private readonly ApplicationSecuritySignalService $signals,
    ) {}

    public function recordCrossTenantAttempt(
        Request $request,
        ?User $actor = null,
        ?string $sourceIp = null,
    ): void {
        $this->recorder->recordSecurityEvent([
            'id' => (string) Str::uuid(),
            'event_type' => 'CROSS_TENANT_ATTEMPT',
            'severity' => 'HIGH',
            'actor_user_id' => $actor?->id,
            'tenant_id' => $actor?->tenant_id,
            'source_ip' => $sourceIp ?? $request->ip(),
            'authorization_result' => 'denied',
            'reason_code' => 'cross_tenant',
            'request_id' => $this->requestId($request),
            'detected_at' => now(),
            'metadata' => [
                'normalized_route' => '/'.ltrim($request->path(), '/'),
                'http_method' => $request->method(),
            ],
        ]);
    }

    public function recordSupportSessionViolation(Request $request, string $reasonCode): void
    {
        $actor = $request->user();

        $this->recorder->recordSecurityEvent([
            'id' => (string) Str::uuid(),
            'event_type' => 'SUPPORT_SESSION_VIOLATION',
            'severity' => 'MEDIUM',
            'actor_user_id' => $actor instanceof User ? $actor->id : null,
            'tenant_id' => $actor instanceof User ? $actor->tenant_id : null,
            'source_ip' => $request->ip(),
            'authorization_result' => 'denied',
            'reason_code' => $reasonCode,
            'request_id' => $this->requestId($request),
            'detected_at' => now(),
            'metadata' => [
                'normalized_route' => '/'.ltrim($request->path(), '/'),
                'http_method' => $request->method(),
            ],
        ]);

        $this->signals->upsertMinuteWindow('SUPPORT_SESSION_VIOLATION', $request->ip(), [
            'risk_level' => 'HIGH',
        ]);
    }

    public function maybeRecordFromDeniedResponse(Request $request, Response $response): void
    {
        if ($response->getStatusCode() !== 403) {
            return;
        }

        $payload = json_decode($response->getContent() ?: '', true);
        if (! is_array($payload)) {
            return;
        }

        $haystack = strtolower(json_encode($payload));
        if (str_contains($haystack, 'cross_tenant') || str_contains($haystack, 'cross-tenant')) {
            $actor = $request->user();
            $this->recordCrossTenantAttempt(
                $request,
                $actor instanceof User ? $actor : null,
            );
        }
    }

    private function requestId(Request $request): ?string
    {
        return $request->attributes->get(AssignRequestId::ATTRIBUTE)
            ?? $request->header(AssignRequestId::HEADER);
    }
}
