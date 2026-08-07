<?php

namespace Modules\Family\app\Services;

use Illuminate\Support\Facades\Auth;
use Modules\Family\Models\FamilyAuditLog;
use Modules\Tenants\Support\TenantContext;

class FamilyAuditService
{
    public function log(
        int $tenantId,
        string $event,
        string $targetType,
        string $targetId,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $metadata = []
    ): FamilyAuditLog {
        $supportSessionId = null;
        try {
            $supportSessionId = app(TenantContext::class)->supportSessionId();
        } catch (\Throwable) {
            $supportSessionId = null;
        }

        return FamilyAuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => Auth::id(),
            'support_session_id' => $supportSessionId,
            'event' => $event,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
        ]);
    }
}
