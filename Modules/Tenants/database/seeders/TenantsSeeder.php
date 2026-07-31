<?php

namespace Modules\Tenants\database\seeders;

use Illuminate\Database\Seeder;
use Modules\Tenants\Models\Tenant;

class TenantsSeeder extends Seeder
{
    public function run(): void
    {
        // Create a demo tenant if not exists
        Tenant::factory()->create([ 'name' => 'Demo Parish' ]);
        Tenant::factory()->count(2)->create();
    }
}
