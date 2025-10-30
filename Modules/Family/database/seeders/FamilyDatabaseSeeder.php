<?php

namespace Modules\Family\Database\Seeders;

use Illuminate\Database\Seeder;

class FamilyDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            FamilyModuleSeeder::class,
        ]);
    }
}
