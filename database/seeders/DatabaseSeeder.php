<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Authentication\Database\Seeders\AuthenticationDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->command->info('🌱 Seeding EkklesiaSoft Database...');
        $this->command->line('');
        $this->command->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->line('');

        // Seed Authentication Module (includes roles)
        $this->call(AuthenticationDatabaseSeeder::class);

        // Seed Tenants Module
        $this->call(\Modules\Tenants\Database\Seeders\TenantsDatabaseSeeder::class);

        // Seed RolesAndPermissions Module
        $this->call(\Modules\RolesAndPermissions\Database\Seeders\RolesAndPermissionsDatabaseSeeder::class);

        // Add other module seeders here as they are created
        // Example:
        // $this->call(SettingsDatabaseSeeder::class);

        $this->command->line('');
        $this->command->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->line('');
        $this->command->info('🎉 Database seeding completed successfully!');
    }
}
