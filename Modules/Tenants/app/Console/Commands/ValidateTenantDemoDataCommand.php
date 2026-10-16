<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Modules\Tenants\Database\Seeders\Support\CompactDemoValidator;
use Modules\Tenants\Database\Seeders\Support\DemoSeedGuard;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\TenantDemoResolver;
use Modules\Tenants\Models\Tenant;

class ValidateTenantDemoDataCommand extends Command
{
    protected $signature = 'tenants:validate-demo-data
        {--tenant= : Parish tenant id}
        {--expect-compact : Fail with exit code 2 when compact shape is not met}';

    protected $description = 'Validate demo-marked parish fixtures for a tenant (integrity and optional compact shape).';

    public function handle(): int
    {
        if ($this->option('tenant')) {
            $id = (string) $this->option('tenant');
            putenv(TenantDemoMarkers::ENV_TENANT_ID.'='.$id);
            $_ENV[TenantDemoMarkers::ENV_TENANT_ID] = $id;
            $_SERVER[TenantDemoMarkers::ENV_TENANT_ID] = $id;
        }

        $explicit = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $tenant = DemoSeedGuard::resolveTenant($explicit);
        if ($tenant === null) {
            $envId = TenantDemoResolver::readEnvTenantId();
            if ($envId !== null) {
                $tenant = Tenant::query()->find($envId);
            }
        }

        if ($tenant === null) {
            $this->error('Valid --tenant= id is required.');

            return CompactDemoValidator::EXIT_INTEGRITY;
        }

        $tenantId = (int) $tenant->id;
        $counts = CompactDemoValidator::countTable($tenantId);
        $this->table(['Metric', 'Count'], collect($counts)->map(fn ($v, $k) => [$k, (string) $v])->values()->all());

        $integrity = CompactDemoValidator::integrityErrors($tenantId);
        if ($integrity !== []) {
            $this->error('Integrity failures:');
            foreach ($integrity as $message) {
                $this->error('  • '.$message);
            }

            return CompactDemoValidator::EXIT_INTEGRITY;
        }

        $this->info('Integrity checks passed.');

        if ($this->option('expect-compact')) {
            $shape = CompactDemoValidator::compactShapeErrors($tenantId);
            if ($shape !== []) {
                $this->warn('Compact shape not met (legacy volume may remain):');
                foreach ($shape as $message) {
                    $this->warn('  • '.$message);
                }

                return CompactDemoValidator::EXIT_COMPACT_SHAPE;
            }

            $this->info('Compact shape checks passed.');
        }

        return CompactDemoValidator::EXIT_OK;
    }
}
