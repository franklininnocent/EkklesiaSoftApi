<?php

namespace Modules\MassIntentions\Services;

use Modules\Authentication\Models\User;
use Modules\MassIntentions\Models\MassIntentionAudit;

class MassIntentionAuditService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        int $tenantId,
        string $eventType,
        ?User $actor = null,
        ?string $requestId = null,
        ?string $celebrationId = null,
        array $payload = []
    ): void {
        MassIntentionAudit::query()->create([
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'request_id' => $requestId,
            'celebration_id' => $celebrationId,
            'actor_user_id' => $actor?->id,
            'payload' => $payload === [] ? null : $payload,
            'created_at' => now(),
        ]);
    }
}
