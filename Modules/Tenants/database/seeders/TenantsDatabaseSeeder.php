<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        // Then seed ecclesiastical reference data (denominations, archdioceses, titles, orders, bishops)
        // Finally seed subscription plans and duration options
        $this->seedIfEmpty('countries', GeographicDataSeeder::class);
        $this->call(DenominationsSeeder::class);
        $this->seedIfEmpty('archdioceses', ComprehensiveArchdiocesesSeeder::class);
        $this->seedIfEmpty('ecclesiastical_titles', EcclesiasticalTitlesSeeder::class);
        $this->seedIfEmpty('religious_orders', ReligiousOrdersSeeder::class);
        $this->seedIfEmpty('bishops', TamilNaduBishopsSeeder::class);

        $this->call([
            SubscriptionPlansSeeder::class,
            SubscriptionDurationOptionsSeeder::class,
            PopeDetailsSeeder::class,
            LeadershipRolesSeeder::class,
        ]);

        $this->command->line('');
        $this->command->info('✅ Tenants Module seeded successfully!');
    }

    private function seedIfEmpty(string $table, string $seederClass): void
    {
        if (! Schema::hasTable($table)) {
            $this->command->warn("Skipping {$seederClass}: {$table} table missing.");

            return;
        }

        if (DB::table($table)->count() > 0) {
            $this->command->warn("Skipping {$seederClass}: {$table} already seeded.");

            return;
        }

        $this->call($seederClass);
    }
}
