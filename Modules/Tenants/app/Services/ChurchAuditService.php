<?php

namespace Modules\Tenants\Services;

use Illuminate\Support\Facades\Auth;
use Modules\Tenants\Models\ChurchAuditLog;
use Modules\Tenants\Support\TenantContext;

class ChurchAuditService
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
        ?int $churchProfileId = null,
        array $metadata = [],
    ): ChurchAuditLog {
        $supportSessionId = null;
        try {
            $supportSessionId = app(TenantContext::class)->supportSessionId();
        } catch (\Throwable) {
            $supportSessionId = null;
        }

        return ChurchAuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => Auth::id(),
            'support_session_id' => $supportSessionId,
            'event' => $event,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'church_profile_id' => $churchProfileId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);
    }
}
