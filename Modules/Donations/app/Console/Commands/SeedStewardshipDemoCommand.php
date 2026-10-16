<?php

namespace Modules\Donations\Console\Commands;

use Illuminate\Console\Command;
use Modules\Donations\Database\Seeders\StewardshipDemoSeeder;

class SeedStewardshipDemoCommand extends Command
{
    protected $signature = 'donations:seed-stewardship-demo {--tenant= : Tenant id}';

    protected $description = 'Seed realistic stewardship plans, projects, dues, and payments for BCC families (demo).';

    public function handle(): int
    {
        if ($this->option('tenant')) {
            putenv('STEWARDSHIP_DEMO_TENANT_ID='.$this->option('tenant'));
            $_ENV['STEWARDSHIP_DEMO_TENANT_ID'] = $this->option('tenant');
        }

        $this->call('db:seed', [
            '--class' => StewardshipDemoSeeder::class,
            '--force' => true,
        ]);

        return self::SUCCESS;
    }
}
