<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Tenants\Database\Seeders\Support\SacramentsDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\SacramentsDemoRegisterPurge;
use Modules\Tenants\Database\Seeders\Support\SacramentsDemoSeederEngine;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\TenantDemoResolver;

/**
 * Parish sacrament register demo data (dashboard, list, filters, pagination).
 *
 * Run via {@see TenantDemoDataOrchestratorSeeder} or:
 * TENANT_DEMO_TENANT_ID=1 php artisan db:seed --class=Modules\\Tenants\\Database\\Seeders\\TenantSacramentsDemoSeeder
 */
class TenantSacramentsDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (TenantDemoResolver::flagIsTrue(TenantDemoMarkers::ENV_SKIP_SACRAMENTS)) {
            $this->command?->warn('Skipped sacraments demo (TENANT_DEMO_SKIP_SACRAMENTS).');

            return;
        }

        $tenant = TenantDemoResolver::resolveTenant();
        if (! $tenant) {
            $this->command?->error('Tenant not found for sacraments demo.');

            return;
        }

        $tenantId = (int) $tenant->id;
        $actor = TenantDemoResolver::resolveActor($tenantId);
        if (! $actor) {
            $this->command?->warn('Skipping sacraments demo: no parish user.');

            return;
        }

        TenantDemoResolver::bindTenantContext($actor, $tenantId);

        if (TenantDemoResolver::flagIsTrue(SacramentsDemoMarkers::ENV_RESET)) {
            $purged = SacramentsDemoRegisterPurge::purgeTenant($tenantId, true);
            $this->command?->warn(sprintf(
                'Sacraments reset: removed %d register row(s) (%d demo, %d incomplete).',
                $purged['total'],
                $purged['demo'],
                $purged['incomplete'],
            ));
        }

        $engine = SacramentsDemoSeederEngine::forTenant($tenant, (int) $actor->id);
        $stats = $engine->run();

        $this->command?->info(sprintf(
            'Sacraments demo — tenant #%d: %d register rows (%s, +%d this run, %d skipped).',
            $tenantId,
            $stats['total_demo_rows'],
            $stats['phase'],
            $stats['created_this_run'],
            $stats['skipped_this_run'],
        ));

        if ($stats['by_type'] !== []) {
            $this->command?->info('  By type: '.json_encode($stats['by_type']));
        }
    }
}
