<?php

namespace Modules\Authentication\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;

class SuperAdminUserSeeder extends Seeder
{
    private const OWNER_EMAIL = 'franklininnocent.fs@gmail.com';

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $now = Carbon::now();

        $superAdminRole = Role::query()
            ->where('name', Role::SUPER_ADMIN)
            ->whereNull('tenant_id')
            ->first();

        if (! $superAdminRole) {
            $this->command->error('❌ [Authentication Module] SuperAdmin role not found! Please run RolesTableSeeder first.');

            return;
        }

        $existingUser = User::query()->where('email', self::OWNER_EMAIL)->first();

        if ($existingUser) {
            $existingUser->forceFill([
                'role_id' => $superAdminRole->id,
                'tenant_id' => null,
                'active' => 1,
                'updated_at' => $now,
            ])->save();

            $existingUser->syncRoles([$superAdminRole->id]);

            $this->command->warn('⚠️  [Authentication Module] Super Admin user already exists — role linkage refreshed.');

            return;
        }

        $userId = DB::table('users')->insertGetId([
            'name' => 'Franklin Innocent F',
            'email' => self::OWNER_EMAIL,
            'email_verified_at' => $now,
            'password' => Hash::make('Secrete*999'),
            'role_id' => $superAdminRole->id,
            'tenant_id' => null,
            'active' => 1,
            'deleted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        User::query()->find($userId)?->syncRoles([$superAdminRole->id]);

        $this->command->info('✅ [Authentication Module] Super Admin user created successfully!');
        $this->command->info('   📧 Email: '.self::OWNER_EMAIL);
        $this->command->info('   🔑 Password: Secrete*999');
        $this->command->info('   👤 Role: SuperAdmin');
        $this->command->info('   🆔 User ID: '.$userId);
        $this->command->line('');
        $this->command->info('🎉 You can now login with these credentials!');
    }
}
