<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;

class TenantsDatabaseSeeder extends Seeder
{
    /**
     * Run the Tenants module database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $this->command->info('🏢 Seeding Tenants Module...');
        $this->command->line('');

        // Seed geographic data first (countries and states)
        $this->call([
            GeographicDataSeeder::class,
            TenantsTableSeeder::class,
        ]);

        $this->command->line('');
        $this->command->info('✅ Tenants Module seeded successfully!');
    }
}
