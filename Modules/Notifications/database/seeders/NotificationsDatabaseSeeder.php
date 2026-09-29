<?php

namespace Modules\Notifications\Database\Seeders;

use Illuminate\Database\Seeder;

class NotificationsDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            NotificationDefinitionsSeeder::class,
            NotificationsPermissionSeeder::class,
        ]);
    }
}
