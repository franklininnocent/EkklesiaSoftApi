<?php

namespace Modules\RolesAndPermissions\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\BCC\Database\Seeders\BccPermissionSeeder;
use Modules\MinistriesAssociations\Database\Seeders\MinistriesAssociationsPermissionSeeder;
use Modules\PastoralCare\Database\Seeders\PastoralCarePermissionSeeder;

class RolesAndPermissionsDatabaseSeeder extends Seeder
{
    /**
     * Run the RolesAndPermissions module database seeds.
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

        if (class_exists(MinistriesAssociationsPermissionSeeder::class)) {
            $this->call(MinistriesAssociationsPermissionSeeder::class);
        }

        if (class_exists(BccPermissionSeeder::class)) {
            $this->call(BccPermissionSeeder::class);
        }

        if (class_exists(PastoralCarePermissionSeeder::class)) {
            $this->call(PastoralCarePermissionSeeder::class);
        }

        $this->command->line('');
        $this->command->info('✅ RolesAndPermissions Module seeded successfully!');
    }
}
