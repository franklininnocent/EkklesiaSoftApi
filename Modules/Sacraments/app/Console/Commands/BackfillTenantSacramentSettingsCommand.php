<?php

namespace Modules\Sacraments\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sacraments\Services\TenantSacramentSettingsService;
use Modules\Tenants\Models\Tenant;

class BackfillTenantSacramentSettingsCommand extends Command
{
    protected $signature = 'sacraments:backfill-tenant-settings
        {--tenant-id= : Limit to one tenant}
        {--dry-run : Preview without writing}';

    protected $description = 'Idempotently seed tenant sacrament availability rows (all types Active by default).';

    public function handle(TenantSacramentSettingsService $settings): int
    {
        $tenantId = $this->option('tenant-id');
        $tenantId = ($tenantId !== null && $tenantId !== '') ? (int) $tenantId : null;
        $dryRun = (bool) $this->option('dry-run');

        $query = Tenant::query()->orderBy('id');
        if ($tenantId) {
            $query->where('id', $tenantId);
        }

        $tenants = $query->get(['id', 'name']);
        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            if ($dryRun) {
                $this->line("Would seed sacrament settings for tenant #{$tenant->id} ({$tenant->name}).");

                continue;
            }

            $settings->ensureDefaults((int) $tenant->id, null);
            $this->info("Seeded sacrament settings for tenant #{$tenant->id} ({$tenant->name}).");
        }

        return self::SUCCESS;
    }
}
