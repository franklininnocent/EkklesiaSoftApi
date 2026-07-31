<?php

namespace Modules\Donations\Services;

use Illuminate\Support\Facades\Auth;
use Modules\Donations\Models\DonationAuditLog;

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
        return DonationAuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => Auth::id(),
            'event' => $event,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
        ]);
    }
}
