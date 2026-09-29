<?php

namespace Modules\MinistriesAssociations\Services;

use Illuminate\Support\Facades\Auth;
use Modules\MinistriesAssociations\Models\MinistriesAuditLog;
use Modules\Tenants\Support\TenantContext;

class MinistriesAuditService
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        int $tenantId,
        string $event,
        string $targetType,
        string $targetId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $organizationId = null,
        array $metadata = [],
    ): MinistriesAuditLog {
        $supportSessionId = null;
        try {
            $supportSessionId = app(TenantContext::class)->supportSessionId();
        } catch (\Throwable) {
            $supportSessionId = null;
        }

        return MinistriesAuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => Auth::id(),
            'support_session_id' => $supportSessionId,
            'event' => $event,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'organization_id' => $organizationId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);
    }
}
