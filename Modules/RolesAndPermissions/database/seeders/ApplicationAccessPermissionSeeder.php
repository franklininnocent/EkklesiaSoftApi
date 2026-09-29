<?php

namespace Modules\RolesAndPermissions\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\ApplicationAccess\Support\ApplicationAccessPermissionCatalog;

class ApplicationAccessPermissionSeeder extends Seeder
{
    public function run(): void
    {
        ApplicationAccessPermissionCatalog::syncPermissionsAndRoles();
        $this->command?->info('✅ Application Access permissions seeded');
    }
}
