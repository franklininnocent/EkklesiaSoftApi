<?php

namespace Modules\RolesAndPermissions\Database\Seeders;

use Illuminate\Database\Seeder;

class RolesAndPermissionsDatabaseSeeder extends Seeder
{
    /**
     * Run the RolesAndPermissions module database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $this->command->info('🔐 Seeding RolesAndPermissions Module...');
        $this->command->line('');

        // Seed permissions
        $this->call([
            PermissionsTableSeeder::class,
        ]);

        $this->command->line('');
        $this->command->info('✅ RolesAndPermissions Module seeded successfully!');
    }
}

