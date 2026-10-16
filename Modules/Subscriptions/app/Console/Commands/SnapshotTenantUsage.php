<?php

namespace Modules\Subscriptions\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Services\Entitlements\EntitlementCatalog;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Subscriptions\Services\UsageService;
use Modules\Tenants\Models\Tenant;

class SnapshotTenantUsage extends Command
{
    protected $signature = 'subscriptions:snapshot-usage';

    protected $description = 'Record a daily usage snapshot per tenant for each measurable limit feature';

    public function handle(EntitlementResolver $resolver, EntitlementCatalog $catalog, UsageService $usage): int
    {
        $today = now()->toDateString();
        $count = 0;

        Tenant::query()->orderBy('id')->chunkById(100, function ($tenants) use ($resolver, $catalog, $usage, $today, &$count): void {
            foreach ($tenants as $tenant) {
                $resolved = $resolver->resolve($tenant);
                foreach ($usage->summary($resolved) as $row) {
                    $featureId = $catalog->feature($row['code'])['id'] ?? null;
                    if (! $featureId) {
                        continue;
                    }
                    DB::table('tenant_usage_snapshots')->updateOrInsert(
                        ['tenant_id' => $tenant->id, 'feature_id' => $featureId, 'snapshot_date' => $today],
                        ['usage_value' => $row['usage'], 'limit_value' => $row['limit'], 'created_at' => now()]
                    );
                    $count++;
                }
            }
            $resolver->flushMemo();
        });

        $this->info("Recorded {$count} usage snapshots.");

        return self::SUCCESS;
    }
}
