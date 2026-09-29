<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\TenantDemoDataOrchestratorSeeder;

class SeedTenantDemoDataCommand extends Command
{
    protected $signature = 'tenants:seed-demo-data
        {--tenant= : Parish tenant id}
        {--skip-families : Skip BccDummyFamiliesSeeder (large)}
        {--skip-stewardship : Skip StewardshipDemoSeeder}
        {--skip-bcc-leadership : Skip BccLeadershipDemoSeeder}
        {--dry-run : Print plan without writing}';

    protected $description = 'Idempotent demo data for one parish tenant (BCC, families, stewardship, church profile, pastoral care, ministries, notifications).';

    public function handle(): int
    {
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

        if ($this->option('dry-run')) {
            $this->putEnv(TenantDemoMarkers::ENV_DRY_RUN, '1');
        }

        $this->call('db:seed', [
            '--class' => TenantDemoDataOrchestratorSeeder::class,
            '--force' => true,
        ]);

        return self::SUCCESS;
    }

    private function putEnv(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
