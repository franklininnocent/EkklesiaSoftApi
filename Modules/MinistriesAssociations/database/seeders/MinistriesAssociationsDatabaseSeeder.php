<?php

namespace Modules\MinistriesAssociations\Database\Seeders;

use Illuminate\Database\Seeder;

class MinistriesAssociationsDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            MinistriesAssociationsPermissionSeeder::class,
        ]);

        // Per-tenant taxonomy/org defaults are applied by MinistriesAssociationsDefaultSeeder
        // from tenant provisioning and `php artisan ministries:seed-defaults`.
    }
}
