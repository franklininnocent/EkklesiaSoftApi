<?php

namespace Modules\Authentication\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class SuperAdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $now = Carbon::now();

        // Get SuperAdmin role ID
        $superAdminRoleId = DB::table('roles')->where('name', 'SuperAdmin')->value('id');

        if (!$superAdminRoleId) {
            $this->command->error('❌ [Authentication Module] SuperAdmin role not found! Please run RolesTableSeeder first.');
            return;
        }

        // Check if Super Admin user already exists
        $existingUser = DB::table('users')
            ->where('email', 'franklininnocent.fs@gmail.com')
            ->first();

        if ($existingUser) {
            $this->command->warn('⚠️  [Authentication Module] Super Admin user already exists!');
            return;
        }

        // Create Super Admin user
        $userId = DB::table('users')->insertGetId([
            'name' => 'Franklin Innocent F',
            'email' => 'franklininnocent.fs@gmail.com',
            'email_verified_at' => $now,
            'password' => Hash::make('Secrete*999'),
            'role_id' => $superAdminRoleId,
            'tenant_id' => null, // Super Admin is not tied to any tenant
            'active' => 1,
            'deleted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->command->info('✅ [Authentication Module] Super Admin user created successfully!');
        $this->command->info('   📧 Email: franklininnocent.fs@gmail.com');
        $this->command->info('   🔑 Password: Secrete*999');
        $this->command->info('   👤 Role: SuperAdmin');
        $this->command->info('   🆔 User ID: ' . $userId);
        $this->command->line('');
        $this->command->info('🎉 You can now login with these credentials!');
    }
}

