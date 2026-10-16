<?php

namespace Modules\Authentication\Support;

use Illuminate\Support\Facades\DB;
use Modules\Tenants\Contracts\UsageMetricProvider;

/**
 * Staff seats = active, non-deleted user accounts belonging to the tenant.
 */
final class StaffUserUsageProvider implements UsageMetricProvider
{
    public function metricCode(): string
    {
        return 'STAFF_USER_LIMIT';
    }

    public function currentUsage(int $tenantId): int
    {
        return (int) DB::table('users')
            ->where('tenant_id', $tenantId)
            ->where('active', 1)
            ->whereNull('deleted_at')
            ->count();
    }
}
