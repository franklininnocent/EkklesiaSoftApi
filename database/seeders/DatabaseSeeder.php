<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Core seeders (if any)
        
        // Module seeders
        $this->callIfExists([ 
            \Modules\Tenants\database\seeders\TenantsSeeder::class,
            \Modules\Authentication\database\seeders\UsersSeeder::class,
            \Modules\BCC\database\seeders\BCCsSeeder::class,
            \Modules\Family\database\seeders\FamiliesSeeder::class,
            \Modules\Sacraments\database\seeders\SacramentTypesSeeder::class,
            \Modules\Sacraments\database\seeders\SacramentsSeeder::class,
        ]);
    }

    private function callIfExists(array $seeders): void
    {
        foreach ($seeders as $seeder) {
            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
