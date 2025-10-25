<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Tenants\Models\Tenant;
use Carbon\Carbon;

class TenantsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $now = Carbon::now();

        // Create demo tenants
        $tenants = [
            [
                'name' => 'First Baptist Church',
                'slug' => 'first-baptist-church',
                'email' => 'admin@firstbaptist.com',
                'phone' => '(555) 123-4567',
                'address' => '123 Church Street',
                'city' => 'Nashville',
                'state' => 'Tennessee',
                'country' => 'USA',
                'postal_code' => '37201',
                'plan' => 'premium',
                'max_users' => 100,
                'max_storage_mb' => 5000,
                'trial_ends_at' => null,
                'subscription_ends_at' => $now->copy()->addYear(),
                'active' => 1,
                'settings' => [
                    'timezone' => 'America/Chicago',
                    'language' => 'en',
                    'currency' => 'USD',
                ],
                'features' => ['donations', 'events', 'groups', 'messaging'],
                'primary_color' => '#1E40AF',
                'secondary_color' => '#10B981',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Grace Community Church',
                'slug' => 'grace-community-church',
                'email' => 'info@gracechurch.org',
                'phone' => '(555) 234-5678',
                'address' => '456 Grace Avenue',
                'city' => 'Austin',
                'state' => 'Texas',
                'country' => 'USA',
                'postal_code' => '73301',
                'plan' => 'basic',
                'max_users' => 50,
                'max_storage_mb' => 1000,
                'trial_ends_at' => $now->copy()->addDays(30),
                'subscription_ends_at' => null,
                'active' => 1,
                'settings' => [
                    'timezone' => 'America/Chicago',
                    'language' => 'en',
                    'currency' => 'USD',
                ],
                'features' => ['donations', 'events'],
                'primary_color' => '#7C3AED',
                'secondary_color' => '#F59E0B',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'New Life Ministry',
                'slug' => 'new-life-ministry',
                'email' => 'contact@newlifeministry.com',
                'phone' => '(555) 345-6789',
                'address' => '789 Ministry Road',
                'city' => 'Dallas',
                'state' => 'Texas',
                'country' => 'USA',
                'postal_code' => '75201',
                'plan' => 'free',
                'max_users' => 10,
                'max_storage_mb' => 100,
                'trial_ends_at' => null,
                'subscription_ends_at' => null,
                'active' => 1,
                'settings' => [
                    'timezone' => 'America/Chicago',
                    'language' => 'en',
                    'currency' => 'USD',
                ],
                'features' => ['events'],
                'primary_color' => '#3B82F6',
                'secondary_color' => '#10B981',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($tenants as $tenantData) {
            Tenant::create($tenantData);
        }

        $this->command->info('✅ [Tenants Module] Sample tenants created successfully!');
        $this->command->info('   - First Baptist Church (Premium)');
        $this->command->info('   - Grace Community Church (Basic - Trial)');
        $this->command->info('   - New Life Ministry (Free)');
    }
}

