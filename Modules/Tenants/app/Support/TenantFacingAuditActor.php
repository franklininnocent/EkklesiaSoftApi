<?php

namespace Modules\Tenants\Support;

/**
 * Tenant-facing audit/activity actor display.
 *
 * Stored support_session_id is the only signal. Real actor_user_id is never replaced.
 */
final class TenantFacingAuditActor
{
    public const DISPLAY_NAME = 'Ekklesia Support';

    public static function displayName(?string $realName, ?string $supportSessionId): ?string
    {
        if (is_string($supportSessionId) && trim($supportSessionId) !== '') {
            return self::DISPLAY_NAME;
        }

        return $realName;
    }
}
