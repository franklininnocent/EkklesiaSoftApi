<?php

namespace Modules\Family\Support\Usage;

use Illuminate\Support\Facades\DB;
use Modules\Tenants\Contracts\UsageMetricProvider;

final class FamilyUsageProvider implements UsageMetricProvider
{
    public function metricCode(): string
    {
        return 'FAMILY_LIMIT';
    }

    public function currentUsage(int $tenantId): int
    {
        return (int) DB::table('families')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->count();
    }
}
