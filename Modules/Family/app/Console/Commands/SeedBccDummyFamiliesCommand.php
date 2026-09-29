<?php

namespace Modules\Family\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\Family\Database\Seeders\BccDummyFamiliesSeeder;

class SeedBccDummyFamiliesCommand extends Command
{
    protected $signature = 'family:seed-bcc-dummies
                            {--tenant= : Tenant id (overrides BCC_DUMMY_TENANT_ID)}
                            {--dry-run : Plan only; no database writes}
                            {--min=40 : Minimum families per BCC}
                            {--max=50 : Maximum families per BCC}';

    protected $description = 'Seed 40–50 realistic dummy families per BCC (idempotent; uses FamilyService).';

    public function handle(): int
    {
        if ($this->option('tenant')) {
            putenv('BCC_DUMMY_TENANT_ID='.$this->option('tenant'));
            $_ENV['BCC_DUMMY_TENANT_ID'] = $this->option('tenant');
        }

        putenv('BCC_DUMMY_FAMILIES_MIN='.$this->option('min'));
        putenv('BCC_DUMMY_FAMILIES_MAX='.$this->option('max'));
        $_ENV['BCC_DUMMY_FAMILIES_MIN'] = $this->option('min');
        $_ENV['BCC_DUMMY_FAMILIES_MAX'] = $this->option('max');

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
