<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Modules\Tenants\Database\Seeders\Support\DemoSeedGuard;
use Modules\Tenants\Database\Seeders\Support\SacramentsDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\TenantDemoDataOrchestratorSeeder;

class SeedTenantDemoDataCommand extends Command
{
    protected $signature = 'tenants:seed-demo-data
        {--tenant= : Parish tenant id}
        {--skip-families : Skip BccDummyFamiliesSeeder}
        {--skip-stewardship : Skip StewardshipDemoSeeder}
        {--skip-bcc-leadership : Skip BccLeadershipDemoSeeder}
        {--skip-sacraments : Skip TenantSacramentsDemoSeeder}
        {--reset-sacraments : Purge demo/incomplete sacrament rows before re-seeding}
        {--dry-run : Print plan without writing}
        {--force : Required with APP_ALLOW_DEMO_SEED in production}';

    protected $description = 'Idempotent compact demo data for one parish tenant (5 BCCs, 25 families, stewardship, modules).';

    public function handle(): int
    {
        DemoSeedGuard::setCliForceFromCommand($this);

        if ($this->option('tenant')) {
            $this->putEnv(TenantDemoMarkers::ENV_TENANT_ID, (string) $this->option('tenant'));
        }

        if ($this->option('skip-families')) {
            $this->putEnv(TenantDemoMarkers::ENV_SKIP_FAMILIES, '1');
        }

        if ($this->option('skip-stewardship')) {
            $this->putEnv(TenantDemoMarkers::ENV_SKIP_STEWARDSHIP, '1');
        }

        if ($this->option('skip-bcc-leadership')) {
            $this->putEnv(TenantDemoMarkers::ENV_SKIP_BCC_LEADERSHIP, '1');
        }

        if ($this->option('skip-sacraments')) {
            $this->putEnv(TenantDemoMarkers::ENV_SKIP_SACRAMENTS, '1');
        }

        if ($this->option('reset-sacraments')) {
            $this->putEnv(SacramentsDemoMarkers::ENV_RESET, '1');
        }

        if ($this->option('dry-run')) {
            $this->putEnv(TenantDemoMarkers::ENV_DRY_RUN, '1');
        }

        try {
            $this->call('db:seed', [
                '--class' => TenantDemoDataOrchestratorSeeder::class,
                '--force' => true,
            ]);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function putEnv(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
