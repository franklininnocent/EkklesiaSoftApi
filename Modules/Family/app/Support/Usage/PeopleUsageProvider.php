<?php

namespace Modules\Family\Support\Usage;

use Illuminate\Support\Facades\DB;
use Modules\Tenants\Contracts\UsageMetricProvider;

/**
 * People on record = family members (not soft-deleted) in families that still exist.
 */
final class PeopleUsageProvider implements UsageMetricProvider
{
    public function metricCode(): string
    {
        return 'PEOPLE_LIMIT';
    }

    public function currentUsage(int $tenantId): int
    {
        return (int) DB::table('family_members')
            ->where('family_members.tenant_id', $tenantId)
            ->whereNull('family_members.deleted_at')
            ->whereIn('family_members.family_id', function ($query) use ($tenantId): void {
                $query->select('id')
                    ->from('families')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at');
            })
            ->count();
    }
}
