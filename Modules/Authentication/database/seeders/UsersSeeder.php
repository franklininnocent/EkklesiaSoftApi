<?php

namespace Modules\Authentication\database\seeders;

use Illuminate\Database\Seeder;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        // Super admin (no tenant)
        User::firstOrCreate(
            ['email' => 'admin@ekklesiasoft.test'],
            [
                'name' => 'System Admin',
                'password' => Hash::make('Password123!'),
                'user_type' => 1,
            ]
        );

        // Create users for first tenant
        $tenant = Tenant::query()->first();
        if ($tenant) {
            if (!User::where('email', 'tenantuser@test.com')->exists()) {
                User::factory()->create([
                    'tenant_id' => $tenant->id,
                    'email' => 'tenantuser@test.com',
                    'password' => Hash::make('Password123!'),
                    'user_type' => 2,
                ]);
            }
            User::factory()->count(3)->create([ 'tenant_id' => $tenant->id ]);
        }
    }
}
