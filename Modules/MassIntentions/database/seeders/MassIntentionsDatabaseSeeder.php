<?php

namespace Modules\MassIntentions\Database\Seeders;

use Illuminate\Database\Seeder;

class MassIntentionsDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            MassIntentionsPermissionSeeder::class,
        ]);
    }
}
