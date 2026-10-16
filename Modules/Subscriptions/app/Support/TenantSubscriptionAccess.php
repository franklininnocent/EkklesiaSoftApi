<?php

namespace Modules\Subscriptions\Support;

use Modules\Authentication\Models\User;
use Modules\Tenants\Services\SupportSessionAuthorizationService;

/**
 * Who may see a church's commercial subscription details and ask Ekklesia for a plan change.
 */
final class TenantSubscriptionAccess
{
    public static function canView(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->isSuperAdmin()
            || $user->isEkklesiaAdmin()
            || (bool) $user->is_primary_admin
            || $user->isTenantAdmin()
            || app(SupportSessionAuthorizationService::class)->grantsTenantProductAccess($user)
            || $user->hasPermission('subscription.view');
    }
}
