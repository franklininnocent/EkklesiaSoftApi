<?php

namespace Modules\BCC\Services;

use Illuminate\Support\Facades\Auth;
use Modules\BCC\Models\BccAuditLog;
use Modules\Tenants\Support\TenantContext;

class BccAuditService
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
        ?string $bccId = null,
        array $metadata = [],
    ): BccAuditLog {
        $supportSessionId = null;
        try {
            $supportSessionId = app(TenantContext::class)->supportSessionId();
        } catch (\Throwable) {
            $supportSessionId = null;
        }

        return BccAuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => Auth::id(),
            'support_session_id' => $supportSessionId,
            'event' => $event,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'bcc_id' => $bccId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);
    }
}
