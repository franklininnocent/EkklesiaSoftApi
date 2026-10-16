<?php

namespace Modules\ApplicationAccess\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Placeholder seeder — permission migration lands in Phase 2.
 */
class ApplicationAccessDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            \Modules\RolesAndPermissions\Database\Seeders\ApplicationAccessPermissionSeeder::class,
        ]);
    }
}
