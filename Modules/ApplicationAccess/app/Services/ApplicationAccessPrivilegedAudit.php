<?php

namespace Modules\ApplicationAccess\Services;

use Illuminate\Support\Str;
use Modules\ApplicationAccess\Models\ApplicationAccessSession;
use Modules\ApplicationAccess\Models\ApplicationIpBlockRule;
use Modules\Authentication\Models\User;

class ApplicationAccessPrivilegedAudit
{
    public function __construct(
        private readonly ApplicationAccessRecorder $recorder,
    ) {}

    public function recordSessionRevoke(User $actor, ApplicationAccessSession $session): void
    {
        $this->record($actor, 'revoke_session', 'application_access_session', $session->id, [
            'target_user_id' => $session->user_id,
            'target_session_id' => $session->id,
        ]);
    }

    public function recordIpBlock(User $actor, ApplicationIpBlockRule $rule): void
    {
        $this->record($actor, 'block_ip', 'application_ip_block_rule', $rule->id, [
            'ip_address' => $rule->ip_address,
            'cidr' => $rule->cidr,
        ]);
    }

    public function recordIpUnblock(User $actor, ApplicationIpBlockRule $rule): void
    {
        $this->record($actor, 'unblock_ip', 'application_ip_block_rule', $rule->id, [
            'ip_address' => $rule->ip_address,
            'cidr' => $rule->cidr,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function recordExport(User $actor, string $dataset, array $filters = []): void
    {
        $this->record($actor, 'export', 'application_access_export', $dataset, [
            'dataset' => $dataset,
            'filters' => $filters,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(
        User $actor,
        string $reasonCode,
        string $resourceType,
        string $resourceId,
        array $metadata = [],
    ): void {
        $this->recorder->recordSecurityEvent([
            'id' => (string) Str::uuid(),
            'event_type' => 'PRIVILEGED_OPERATION',
            'severity' => 'MEDIUM',
            'actor_user_id' => $actor->id,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'action' => match ($reasonCode) {
                'revoke_session' => 'REVOKE',
                'block_ip' => 'BLOCK',
                'unblock_ip' => 'UNBLOCK',
                'export' => 'EXPORT',
                default => 'VIEW',
            },
            'authorization_result' => 'allowed',
            'reason_code' => $reasonCode,
            'detected_at' => now(),
            'metadata' => $metadata,
        ]);
    }
}
