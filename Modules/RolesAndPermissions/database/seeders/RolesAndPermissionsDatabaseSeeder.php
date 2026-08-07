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
            TenantPermissionCatalogSeeder::class,
            SupportAccessPermissionSeeder::class,
            SupportAccessRoleSeeder::class,
        ]);

        if (class_exists(\Modules\MinistriesAssociations\Database\Seeders\MinistriesAssociationsPermissionSeeder::class)) {
            $this->call(\Modules\MinistriesAssociations\Database\Seeders\MinistriesAssociationsPermissionSeeder::class);
        }

        $this->command->line('');
        $this->command->info('✅ RolesAndPermissions Module seeded successfully!');
    }
}

