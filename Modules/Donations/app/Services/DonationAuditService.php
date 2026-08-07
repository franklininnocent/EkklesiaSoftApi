<?php

namespace Modules\Donations\Services;

use Illuminate\Support\Facades\Auth;
use Modules\Donations\Models\DonationAuditLog;
use Modules\Tenants\Support\TenantContext;

class DonationAuditService
{
    public function log(
        int $tenantId,
        string $event,
        string $targetType,
        string $targetId,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $metadata = []
    ): DonationAuditLog {
        $supportSessionId = null;
        try {
            $supportSessionId = app(TenantContext::class)->supportSessionId();
        } catch (\Throwable) {
            $supportSessionId = null;
        }

        return DonationAuditLog::create([
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
