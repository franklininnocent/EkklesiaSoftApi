<?php

namespace Modules\Authentication\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\Role;

class RolesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $now = Carbon::now();

        $roles = [
            [
                'name' => Role::SUPER_ADMIN,
                'description' => 'Super Administrator of the Application with full system privileges',
                'level' => Role::LEVEL_SUPER_ADMIN,
            ],
            [
                'name' => Role::EKKLESIA_ADMIN,
                'description' => 'Administrator of the Application with tenant management privileges',
                'level' => Role::LEVEL_EKKLESIA_ADMIN,
            ],
            [
                'name' => Role::EKKLESIA_MANAGER,
                'description' => 'Manager of the Application with limited administrative access',
                'level' => Role::LEVEL_EKKLESIA_MANAGER,
            ],
            [
                'name' => Role::EKKLESIA_USER,
                'description' => 'Standard user of the Application with basic access',
                'level' => Role::LEVEL_EKKLESIA_USER,
            ],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                [
                    'name' => $role['name'],
                    'tenant_id' => null,
                ],
                [
                    'description' => $role['description'],
                    'level' => $role['level'],
                    'active' => 1,
                    'deleted_at' => null,
                    'is_custom' => 0,
                    'role_type' => Role::ROLE_TYPE_PLATFORM,
                    'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $this->command->info('✅ [Authentication Module] Roles table seeded successfully!');
        $this->command->info('   - SuperAdmin (Level 1, platform owner)');
        $this->command->info('   - EkklesiaAdmin (Level 2)');
        $this->command->info('   - EkklesiaManager (Level 3)');
        $this->command->info('   - EkklesiaUser (Level 4)');
    }
}
