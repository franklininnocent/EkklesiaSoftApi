<?php

namespace Modules\Tenants\Services;

use Illuminate\Support\Facades\Auth;
use Modules\Tenants\Models\TenantDataExportAudit;
use Modules\Tenants\Support\TenantContext;

class TenantDataExportAuditService
{
    public const EVENT_REQUESTED = 'EXPORT_REQUESTED';

    public const EVENT_STARTED = 'EXPORT_STARTED';

    public const EVENT_COMPLETED = 'EXPORT_COMPLETED';

    public const EVENT_FAILED = 'EXPORT_FAILED';

    public const EVENT_DOWNLOADED = 'EXPORT_DOWNLOADED';

    public const EVENT_EXPIRED = 'EXPORT_EXPIRED';

    public const EVENT_CANCELLED = 'EXPORT_CANCELLED';

    public const TARGET_TYPE = 'tenant_data_export';

    public function log(
        int $tenantId,
        string $event,
        string $targetType,
        string $targetId,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $metadata = []
    ): TenantDataExportAudit {
        $supportSessionId = null;
        try {
            $supportSessionId = app(TenantContext::class)->supportSessionId();
        } catch (\Throwable) {
            $supportSessionId = null;
        }

        return TenantDataExportAudit::create([
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

    public function logExportEvent(
        int $tenantId,
        string $exportId,
        string $event,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $metadata = []
    ): TenantDataExportAudit {
        return $this->log(
            $tenantId,
            $event,
            self::TARGET_TYPE,
            $exportId,
            $oldValues,
            $newValues,
            $metadata
        );
    }
}
