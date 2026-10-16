<?php

namespace Modules\Notifications\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\RolesAndPermissions\Models\Permission;

class NotificationsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        Permission::updateOrCreate(
            ['name' => 'notifications.view'],
            [
                'display_name' => 'View Notifications',
                'description' => 'View in-app notification inbox',
                'module' => 'Notifications',
                'category' => 'notifications',
            ]
        );
    }
}
