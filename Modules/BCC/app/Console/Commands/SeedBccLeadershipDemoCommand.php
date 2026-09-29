<?php

namespace Modules\BCC\Console\Commands;

use Illuminate\Console\Command;
use Modules\BCC\Database\Seeders\BccLeadershipDemoSeeder;

class SeedBccLeadershipDemoCommand extends Command
{
    protected $signature = 'bcc:seed-leadership-demo {--tenant= : Tenant id}';

    protected $description = 'Assign five BCC leadership roles per community using parish members (demo data).';

    public function handle(): int
    {
        if ($this->option('tenant')) {
            putenv('BCC_LEADERSHIP_DEMO_TENANT_ID='.$this->option('tenant'));
            $_ENV['BCC_LEADERSHIP_DEMO_TENANT_ID'] = $this->option('tenant');
        }

        $this->call('db:seed', [
            '--class' => BccLeadershipDemoSeeder::class,
            '--force' => true,
        ]);

        return self::SUCCESS;
    }
}
