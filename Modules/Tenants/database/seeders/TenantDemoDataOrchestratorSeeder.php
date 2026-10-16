<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Modules\BCC\Database\Seeders\BccLeadershipDemoSeeder;
use Modules\Donations\Database\Seeders\DonationCategorySeeder;
use Modules\Donations\Database\Seeders\StewardshipDemoSeeder;
use Modules\Family\Database\Seeders\BccDummyFamiliesSeeder;
use Modules\MinistriesAssociations\Database\Seeders\MinistriesAssociationsDefaultSeeder;
use Modules\Tenants\Database\Seeders\Support\CompactDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\CompactDemoValidator;
use Modules\Tenants\Database\Seeders\Support\DemoSeedGuard;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\TenantDemoResolver;
use Modules\Tenants\Database\Seeders\Support\TenantDemoVerifier;
use Modules\Tenants\Services\DemoTenantAdminBootstrap;

/**
 * Enterprise demo-data pipeline for a single parish tenant (compact dataset).
 *
 * Run: php artisan tenants:seed-demo-data --tenant=<id>
 */
class TenantDemoDataOrchestratorSeeder extends Seeder
{
    public function run(): void
    {
        if (! DemoSeedGuard::assertEnvironmentAllowed($this->command)) {
            throw new \RuntimeException('Demo seeding blocked by environment guard.');
        }

        if (! DemoSeedGuard::requireExplicitTenantId($this->command)) {
            throw new \RuntimeException('Demo seeding requires an explicit tenant in this environment.');
        }

        $tenant = TenantDemoResolver::resolveTenant();
        if (! $tenant) {
            $requested = TenantDemoResolver::readEnvTenantId();
            if ($requested !== null) {
                $this->command?->error(sprintf(
                    'Tenant #%d was not found (deleted or wrong id).',
                    $requested
                ));
            } else {
                $this->command?->error('No tenant found. Set TENANT_DEMO_TENANT_ID or use --tenant=.');
            }

            throw new \RuntimeException('Demo tenant not found.');
        }

        $tenantId = (int) $tenant->id;
        $lock = Cache::lock(CompactDemoMarkers::LOCK_PREFIX.$tenantId, CompactDemoMarkers::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new \RuntimeException('Another demo seed is already running for tenant #'.$tenantId.'.');
        }

        try {
            $this->runPipeline($tenant, $tenantId);
        } finally {
            $lock->release();
        }
    }

    private function runPipeline(\Modules\Tenants\Models\Tenant $tenant, int $tenantId): void
    {
        TenantDemoResolver::propagateChildSeederEnv($tenantId);

        if (TenantDemoResolver::flagIsTrue(TenantDemoMarkers::ENV_DRY_RUN)) {
            $this->command?->warn('Dry run — would seed tenant #'.$tenantId.' ('.$tenant->name.').');

            return;
        }

        $actor = app(DemoTenantAdminBootstrap::class)->ensurePrimaryAdmin($tenant);
        TenantDemoResolver::bindTenantContext($actor, $tenantId);
        $this->command?->info(
            'Demo primary admin: '.$actor->email.' (password from DEMO_TENANT_ADMIN_PASSWORD or tenants.demo config; not printed).'
        );

        $this->command?->info('');
        $this->command?->info('═══════════════════════════════════════════════════════════');
        $this->command?->info('  Tenant demo data — '.$tenant->name.' (#'.$tenantId.')');
        $this->command?->info('═══════════════════════════════════════════════════════════');
        $this->command?->info('');

        $this->command?->info('Step 1/11 — Module defaults (donations + ministries taxonomy)');
        (new DonationCategorySeeder)->run($tenantId);
        (new MinistriesAssociationsDefaultSeeder)->run($tenantId, $actor?->id);

        $this->command?->info('Step 2/11 — BCC communities (compact catalog)');
        $this->call(TenantBccsDemoSeeder::class);

        if (! TenantDemoResolver::flagIsTrue(TenantDemoMarkers::ENV_SKIP_FAMILIES)) {
            $this->command?->info('Step 3/11 — Compact households (FamilyService + member sacrament profiles)');
            $this->call(BccDummyFamiliesSeeder::class);
        } else {
            $this->command?->warn('Step 3/11 — Skipped families (TENANT_DEMO_SKIP_FAMILIES).');
        }

        $this->command?->info('Step 4/11 — Sacrament register demo (dashboard, list, reports)');
        $this->call(TenantSacramentsDemoSeeder::class);

        if (! TenantDemoResolver::flagIsTrue(TenantDemoMarkers::ENV_SKIP_BCC_LEADERSHIP)) {
            $this->command?->info('Step 5/11 — BCC leadership roles');
            $this->call(BccLeadershipDemoSeeder::class);
        } else {
            $this->command?->warn('Step 5/11 — Skipped BCC leadership.');
        }

        $this->command?->info('Step 6/11 — Church profile, social, statistics');
        $this->call(TenantChurchPresenceDemoSeeder::class);

        $this->command?->info('Step 7/11 — Pastoral care scenarios');
        $this->call(TenantPastoralCareDemoSeeder::class);

        $this->command?->info('Step 8/11 — Ministries & Associations demo');
        $this->call(TenantMinistriesDemoSeeder::class);

        $this->command?->info('Step 9/11 — In-app notifications');
        $this->call(TenantNotificationsDemoSeeder::class);

        if (! TenantDemoResolver::flagIsTrue(TenantDemoMarkers::ENV_SKIP_STEWARDSHIP)) {
            $this->command?->info('Step 10/11 — Stewardship plans, dues, and payments');
            $this->call(StewardshipDemoSeeder::class);
        } else {
            $this->command?->warn('Step 10/11 — Skipped stewardship (TENANT_DEMO_SKIP_STEWARDSHIP).');
        }

        $this->command?->info('Step 11/11 — Mass intentions (when enabled)');
        $this->call(TenantMassIntentionsDemoSeeder::class);

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

        $integrity = CompactDemoValidator::integrityErrors($tenantId);
        if ($integrity !== []) {
            foreach ($integrity as $error) {
                $this->command?->error('Integrity: '.$error);
            }

            throw new \RuntimeException('Tenant demo integrity verification failed.');
        }

        $shape = CompactDemoValidator::compactShapeErrors($tenantId);
        if ($shape !== []) {
            $this->command?->warn('');
            $this->command?->warn('Compact shape not fully met (legacy demo volume may remain on this tenant):');
            foreach ($shape as $error) {
                $this->command?->warn('  • '.$error);
            }
        } else {
            $this->command?->info('Compact demo shape verified.');
        }

        $this->command?->info('');
        $this->command?->info('Tenant demo seeding complete.');
    }
}
