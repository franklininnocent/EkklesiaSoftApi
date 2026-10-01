<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\BCC\Database\Seeders\BccLeadershipDemoSeeder;
use Modules\Donations\Database\Seeders\DonationCategorySeeder;
use Modules\Donations\Database\Seeders\StewardshipDemoSeeder;
use Modules\Family\Database\Seeders\BccDummyFamiliesSeeder;
use Modules\MinistriesAssociations\Database\Seeders\MinistriesAssociationsDefaultSeeder;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\TenantDemoResolver;
use Modules\Tenants\Database\Seeders\Support\TenantDemoVerifier;

/**
 * Enterprise demo-data pipeline for a single parish tenant.
 *
 * Run:
 * TENANT_DEMO_TENANT_ID=1 php artisan tenants:seed-demo-data
 *
 * Optional:
 * - TENANT_DEMO_SKIP_FAMILIES=1 (skip heavy household seeding)
 * - TENANT_DEMO_SKIP_STEWARDSHIP=1
 * - TENANT_DEMO_SKIP_BCC_LEADERSHIP=1
 * - TENANT_DEMO_DRY_RUN=1
 */
class TenantDemoDataOrchestratorSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = TenantDemoResolver::resolveTenant();
        if (! $tenant) {
            $requested = TenantDemoResolver::readEnvTenantId();
            if ($requested !== null) {
                $this->command?->error(sprintf(
                    'Tenant #%d was not found (deleted or wrong id). List ids: php artisan tinker --execute="echo \\Modules\\Tenants\\Models\\Tenant::orderBy(\'id\')->pluck(\'name\',\'id\');"',
                    $requested
                ));
            } else {
                $this->command?->error('No tenant found. Set TENANT_DEMO_TENANT_ID to your parish id or create a tenant first.');
            }

            return;
        }

        $tenantId = (int) $tenant->id;
        TenantDemoResolver::propagateChildSeederEnv($tenantId);

        $actor = TenantDemoResolver::resolveActor($tenantId);
        if ($actor) {
            TenantDemoResolver::bindTenantContext($actor, $tenantId);
        } else {
            $this->command?->warn('No parish user found — some steps may be skipped.');
        }

        if (TenantDemoResolver::flagIsTrue(TenantDemoMarkers::ENV_DRY_RUN)) {
            $this->command?->warn('Dry run — would seed tenant #'.$tenantId.' ('.$tenant->name.').');

            return;
        }

        $this->command?->info('');
        $this->command?->info('═══════════════════════════════════════════════════════════');
        $this->command?->info('  Tenant demo data — '.$tenant->name.' (#'.$tenantId.')');
        $this->command?->info('═══════════════════════════════════════════════════════════');
        $this->command?->info('');

        $this->command?->info('Step 1/9 — Module defaults (donations + ministries taxonomy)');
        (new DonationCategorySeeder)->run($tenantId);
        (new MinistriesAssociationsDefaultSeeder)->run($tenantId, $actor?->id);

        $this->command?->info('Step 2/9 — BCC communities');
        $this->call(TenantBccsDemoSeeder::class);

        if (! TenantDemoResolver::flagIsTrue(TenantDemoMarkers::ENV_SKIP_FAMILIES)) {
            $this->command?->info('Step 3/9 — Realistic households (FamilyService + sacraments)');
            $this->call(BccDummyFamiliesSeeder::class);
        } else {
            $this->command?->warn('Step 3/9 — Skipped families (TENANT_DEMO_SKIP_FAMILIES).');
        }

        if (! TenantDemoResolver::flagIsTrue(TenantDemoMarkers::ENV_SKIP_BCC_LEADERSHIP)) {
            $this->command?->info('Step 4/9 — BCC leadership roles');
            $this->call(BccLeadershipDemoSeeder::class);
        } else {
            $this->command?->warn('Step 4/9 — Skipped BCC leadership.');
        }

        $this->command?->info('Step 5/9 — Church profile, social, statistics');
        $this->call(TenantChurchPresenceDemoSeeder::class);

        $this->command?->info('Step 6/9 — Pastoral care scenarios');
        $this->call(TenantPastoralCareDemoSeeder::class);

        $this->command?->info('Step 7/9 — Ministries & Associations demo');
        $this->call(TenantMinistriesDemoSeeder::class);

        $this->command?->info('Step 8/9 — In-app notifications');
        $this->call(TenantNotificationsDemoSeeder::class);

        if (! TenantDemoResolver::flagIsTrue(TenantDemoMarkers::ENV_SKIP_STEWARDSHIP)) {
            $this->command?->info('Step 9/9 — Stewardship plans, dues, and payments');
            $this->call(StewardshipDemoSeeder::class);
        } else {
            $this->command?->warn('Step 9/9 — Skipped stewardship (TENANT_DEMO_SKIP_STEWARDSHIP).');
        }

        $errors = TenantDemoVerifier::verify($tenantId);
        if ($errors === []) {
            $this->command?->info('');
            $this->command?->info('Tenant demo verification passed.');
        } else {
            $this->command?->warn('');
            $this->command?->warn('Tenant demo verification reported gaps:');
            foreach ($errors as $error) {
                $this->command?->warn('  • '.$error);
            }
        }

        $this->command?->info('');
        $this->command?->info('Tenant demo seeding complete.');
    }
}
