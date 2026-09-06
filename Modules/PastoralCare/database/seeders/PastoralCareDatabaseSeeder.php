<?php

namespace Modules\PastoralCare\Database\Seeders;

use Illuminate\Database\Seeder;

class PastoralCareDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PastoralCarePermissionSeeder::class,
        ]);
    }
}
