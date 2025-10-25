<?php

namespace Modules\Authentication\Database\Seeders;

use Illuminate\Database\Seeder;

class AuthenticationDatabaseSeeder extends Seeder
{
    /**
     * Run the Authentication module database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $this->command->info('🔐 Seeding Authentication Module...');
        $this->command->line('');

        // Seed roles first
        $this->call([
            RolesTableSeeder::class,
            SuperAdminUserSeeder::class,
        ]);

        $this->command->line('');
        $this->command->info('✅ Authentication Module seeded successfully!');
    }
}
