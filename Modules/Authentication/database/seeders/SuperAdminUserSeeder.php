<?php

namespace Modules\Authentication\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use RuntimeException;

class SuperAdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $name = trim((string) config('authentication.super_admin.name'));
        $email = trim((string) config('authentication.super_admin.email'));
        $password = (string) config('authentication.super_admin.password');

        $missing = [];
        if ($name === '') {
            $missing[] = 'SUPER_ADMIN_NAME';
        }
        if ($email === '') {
            $missing[] = 'SUPER_ADMIN_EMAIL';
        }
        if (trim($password) === '') {
            $missing[] = 'SUPER_ADMIN_PASSWORD';
        }

        if ($missing !== []) {
            $keys = implode(', ', $missing);
            $message = '[Authentication Module] Super Admin seeding requires '.$keys.' in .env.';
            $this->command?->error($message);

            throw new RuntimeException($message);
        }

        $now = Carbon::now();

        $superAdminRole = Role::query()
            ->where('name', Role::SUPER_ADMIN)
            ->whereNull('tenant_id')
            ->first();

        if (! $superAdminRole) {
            $this->command->error('❌ [Authentication Module] SuperAdmin role not found! Please run RolesTableSeeder first.');

            return;
        }

        $existingUser = User::query()->where('email', $email)->first();

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
            'name' => $name,
            'email' => $email,
            'email_verified_at' => $now,
            'password' => Hash::make($password),
            'role_id' => $superAdminRole->id,
            'tenant_id' => null,
            'active' => 1,
            'deleted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        User::query()->find($userId)?->syncRoles([$superAdminRole->id]);

        $this->command->info('✅ [Authentication Module] Super Admin user created successfully!');
        $this->command->info('   Email: '.$email);
        $this->command->info('   Password is set from SUPER_ADMIN_PASSWORD in .env');
        $this->command->info('   Role: SuperAdmin');
        $this->command->info('   User ID: '.$userId);
        $this->command->line('');
        $this->command->info('You can now login with these credentials.');
    }
}
