<?php

namespace Modules\BCC\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\BCC\Models\BCC;
use Modules\Tenants\Models\Tenant;

class BCCsSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first();
        if (!$tenant) return;

        // Create 8 simple BCCs for the first tenant
        for ($i = 1; $i <= 8; $i++) {
            BCC::factory()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Community ' . $i . ' ' . substr(uniqid(), -4),
                'meeting_day' => ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'][array_rand(['monday','tuesday','wednesday','thursday','friday','saturday','sunday'])],
                'meeting_time' => now()->setTime(rand(17, 20), 0)->format('H:i'),
                'meeting_frequency' => ['Weekly','Bi-weekly','Monthly'][array_rand(['Weekly','Bi-weekly','Monthly'])],
                'status' => rand(1,100) > 15 ? 'active' : 'inactive',
            ]);
        }
    }
}

