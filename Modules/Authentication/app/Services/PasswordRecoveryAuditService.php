<?php

namespace Modules\Authentication\Services;

use Modules\ApplicationAccess\Services\ApplicationAccessRecorder;
use Modules\ApplicationAccess\Support\IdentifierMasker;
use Modules\Authentication\Models\User;
use Modules\Tenants\Services\PlatformAuditLogger;

class PasswordRecoveryAuditService
{
    public function __construct(
        private readonly PlatformAuditLogger $auditLogger,
        private readonly ApplicationAccessRecorder $accessRecorder,
    ) {
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(string $event, ?User $target = null, array $metadata = []): void
    {
        $payload = $metadata;

        if ($target !== null) {
            $payload['target_user_id'] = $target->id;
        }

        $this->auditLogger->record(
            category: 'auth',
            event: $event,
            tenantId: $target?->tenant_id !== null ? (int) $target->tenant_id : null,
            actorUserId: null,
            metadata: $payload,
        );

        if (isset($metadata['masked_identifier']) && is_string($metadata['masked_identifier'])) {
            $this->accessRecorder->recordSecurityEvent([
                'event_type' => $this->mapSecurityEventType($event),
                'severity' => $this->mapSeverity($event),
                'source_ip' => request()->ip(),
                'detected_at' => now(),
                'metadata' => [
                    'masked_identifier' => $metadata['masked_identifier'],
                    'reason_code' => $event,
                    'target_user_id' => $target?->id,
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordWithActor(string $event, User $actor, ?User $target = null, array $metadata = []): void
    {
        $payload = $metadata;

        if ($target !== null) {
            $payload['target_user_id'] = $target->id;
        }

        $this->auditLogger->record(
            category: 'auth',
            event: $event,
            tenantId: $target?->tenant_id !== null ? (int) $target->tenant_id : ($actor->tenant_id !== null ? (int) $actor->tenant_id : null),
            actorUserId: (int) $actor->id,
            metadata: $payload,
        );
    }

    public function maskedEmail(string $email): string
    {
        return IdentifierMasker::maskEmail($email);
    }

    private function mapSecurityEventType(string $event): string
    {
        return match ($event) {
            'user.password_recovery_blocked',
            'user.password_recovery_daily_limit' => 'AUTH_FAILURE',
            default => 'AUTH_FAILURE',
        };
    }

    private function mapSeverity(string $event): string
    {
        return match ($event) {
            'user.password_recovery_daily_limit' => 'HIGH',
            'user.password_recovery_blocked' => 'MEDIUM',
            default => 'LOW',
        };
    }
}
