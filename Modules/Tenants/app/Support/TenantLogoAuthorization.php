<?php

namespace Modules\Tenants\Support;

use Modules\Authentication\Models\User;
use Modules\Tenants\Services\SupportSessionAuthorizationService;

final class TenantLogoAuthorization
{
    public static function canView(int $tenantId, ?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($viewer->isSuperAdmin() || $viewer->isEkklesiaAdmin() || $viewer->hasEkklesiaRole()) {
            return true;
        }

        if (app(SupportSessionAuthorizationService::class)->grantsTenantProductAccess($viewer)) {
            return true;
        }

        return (int) $viewer->tenant_id === $tenantId;
    }
}
