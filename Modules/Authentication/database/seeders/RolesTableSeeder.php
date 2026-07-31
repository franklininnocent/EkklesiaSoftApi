<?php

namespace Modules\Authentication\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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
                'id' => 1,
                'name' => 'SuperAdmin',
                'description' => 'Super Administrator of the Application with full system privileges',
                'level' => 1,
                'active' => 1,
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'name' => 'EkklesiaAdmin',
                'description' => 'Administrator of the Application with tenant management privileges',
                'level' => 2,
                'active' => 1,
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'name' => 'EkklesiaManager',
                'description' => 'Manager of the Application with limited administrative access',
                'level' => 3,
                'active' => 1,
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 4,
                'name' => 'EkklesiaUser',
                'description' => 'Standard user of the Application with basic access',
                'level' => 4,
                'active' => 1,
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('roles')->insert($roles);

        $this->command->info('✅ [Authentication Module] Roles table seeded successfully!');
        $this->command->info('   - SuperAdmin (Level 1)');
        $this->command->info('   - EkklesiaAdmin (Level 2)');
        $this->command->info('   - EkklesiaManager (Level 3)');
        $this->command->info('   - EkklesiaUser (Level 4)');
    }
}

