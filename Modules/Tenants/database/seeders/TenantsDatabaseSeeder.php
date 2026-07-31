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
        // Then seed ecclesiastical data (denominations, archdioceses, titles, orders, bishops)
        // Finally seed tenants
        $this->call([
            GeographicDataSeeder::class,                // 1. Countries and states
            DenominationsSeeder::class,                 // 2. Christian denominations
            ComprehensiveArchdiocesesSeeder::class,     // 3. Dioceses and archdioceses (normalized)
            EcclesiasticalTitlesSeeder::class,          // 4. Ecclesiastical titles (Archbishop, Bishop, etc.)
            ReligiousOrdersSeeder::class,               // 5. Religious orders and congregations
            TamilNaduBishopsSeeder::class,              // 6. Current bishops of Tamil Nadu dioceses
            TenantsTableSeeder::class,                  // 7. Sample tenants
        ]);

        $this->command->line('');
        $this->command->info('✅ Tenants Module seeded successfully!');
    }
}
