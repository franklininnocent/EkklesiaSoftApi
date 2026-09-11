<?php

namespace Modules\Tenants\Support;

use Modules\Authentication\Models\User;

final class TenantLogoAuthorization
{
    public static function canView(int $tenantId, ?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($viewer->isSuperAdmin() || $viewer->isEkklesiaAdmin()) {
            return true;
        }

        return (int) $viewer->tenant_id === $tenantId;
    }
}
