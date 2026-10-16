<?php

namespace Modules\Subscriptions\Services\Billing;

use Modules\Subscriptions\Models\BillingAuditEvent;

final class BillingAuditService
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        ?int $actorId,
        ?string $actorRole,
        ?int $tenantId,
        ?string $reason,
        ?array $before,
        ?array $after,
        string $category = 'billing',
    ): void {
        BillingAuditEvent::query()->create([
            'category' => $category,
            'actor_id' => $actorId,
            'actor_role' => $actorRole,
            'tenant_id' => $tenantId,
            'action' => $action,
            'reason' => $reason,
            'before' => $this->sanitize($before),
            'after' => $this->sanitize($after),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    private function sanitize(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }
        $forbidden = ['api_secret', 'webhook_secret', 'password', 'token'];
        foreach ($forbidden as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
