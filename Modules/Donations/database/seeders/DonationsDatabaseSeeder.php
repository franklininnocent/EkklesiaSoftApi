<?php

namespace Modules\Donations\Database\Seeders;

use Illuminate\Database\Seeder;

class DonationsDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            DonationsPermissionSeeder::class,
        ]);
    }
}
