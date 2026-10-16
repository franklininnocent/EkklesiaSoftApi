<?php

namespace Modules\Family\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\BCC\Models\BCC;
use Modules\Family\Database\Seeders\BccDummyFamiliesSeeder;
use Modules\Tenants\Database\Seeders\Support\CompactDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\DemoSeedGuard;

class SeedBccDummyFamiliesCommand extends Command
{
    protected $signature = 'family:seed-bcc-dummies
                            {--tenant= : Tenant id (overrides BCC_DUMMY_TENANT_ID)}
                            {--dry-run : Plan only; no database writes}
                            {--min=5 : Minimum families per BCC}
                            {--max=5 : Maximum families per BCC}
                            {--allow-large : Allow more than 25 families total across BCCs}
                            {--force : Required with APP_ALLOW_DEMO_SEED in production}';

    protected $description = 'Seed realistic dummy families per BCC (idempotent; uses FamilyService).';

    public function handle(): int
    {
        DemoSeedGuard::setCliForceFromCommand($this);

        if (! DemoSeedGuard::assertEnvironmentAllowed($this)) {
            return self::FAILURE;
        }

        if ($this->option('tenant')) {
            putenv('BCC_DUMMY_TENANT_ID='.$this->option('tenant'));
            $_ENV['BCC_DUMMY_TENANT_ID'] = $this->option('tenant');
        }

        $min = (int) $this->option('min');
        $max = (int) $this->option('max');

        if (! $this->option('allow-large') && $max > 5) {
            $tenantId = (int) ($this->option('tenant') ?: env('BCC_DUMMY_TENANT_ID', 0));
            $bccCount = $tenantId > 0
                ? BCC::query()->where('tenant_id', $tenantId)->whereNull('deleted_at')->count()
                : 5;
            if ($bccCount * $max > 25) {
                $this->error('Refusing large seed (bccCount × max > 25). Use --allow-large for non-compact QA.');

                return self::FAILURE;
            }
        }

        putenv('BCC_DUMMY_FAMILIES_MIN='.$min);
        putenv('BCC_DUMMY_FAMILIES_MAX='.$max);
        $_ENV['BCC_DUMMY_FAMILIES_MIN'] = (string) $min;
        $_ENV['BCC_DUMMY_FAMILIES_MAX'] = (string) $max;

        if ($min === 5 && $max === 5) {
            putenv(CompactDemoMarkers::ENV_COMPACT.'=1');
            $_ENV[CompactDemoMarkers::ENV_COMPACT] = '1';
        }

        if ($this->option('dry-run')) {
            putenv('BCC_DUMMY_DRY_RUN=1');
            $_ENV['BCC_DUMMY_DRY_RUN'] = '1';
        }

        $this->call('db:seed', [
            '--class' => BccDummyFamiliesSeeder::class,
            '--force' => true,
        ]);

        return self::SUCCESS;
    }
}
