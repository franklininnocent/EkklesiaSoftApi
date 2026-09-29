<?php

namespace Modules\Sacraments\Services;

use Illuminate\Support\Facades\Auth;
use Modules\Sacraments\Models\SacramentAuditLog;
use Modules\Sacraments\Support\SacramentPrivacyClass;
use Modules\Tenants\Support\TenantContext;

class SacramentAuditService
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        int $tenantId,
        string $event,
        string $targetId,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $metadata = [],
        string $targetType = 'sacrament',
        ?string $privacyClass = null
    ): SacramentAuditLog {
        $supportSessionId = null;
        try {
            $supportSessionId = app(TenantContext::class)->supportSessionId();
        } catch (\Throwable) {
            $supportSessionId = null;
        }

        if (SacramentPrivacyClass::isRestricted($privacyClass)) {
            $oldValues = $this->redactRestrictedPayload($oldValues);
            $newValues = $this->redactRestrictedPayload($newValues);
        }

        return SacramentAuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => Auth::id(),
            'support_session_id' => $supportSessionId,
            'event' => $event,
            'target_type' => $targetType,
            'target_id' => (string) $targetId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function redactRestrictedPayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        if (array_key_exists('notes', $payload) && $payload['notes'] !== null) {
            $payload['notes'] = '[redacted]';
        }

        return $payload;
    }
}
