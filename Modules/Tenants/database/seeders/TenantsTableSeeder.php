<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Tenants\Models\Tenant;
use App\Models\Address;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TenantsTableSeeder extends Seeder
{
    /**
     * Run the database seeds with normalized structure
     *
     * @return void
     */
    public function run()
    {
        $now = Carbon::now();
        
        // Get USA country ID
        $usaId = DB::table('countries')->where('name', 'United States')->value('id');

        // Create demo tenants with normalized structure
        $tenantsData = [
            [
                'tenant' => [
                    'name' => 'First Baptist Church',
                    'slug' => 'first-baptist-church',
                    'plan' => 'premium',
                    'max_users' => 100,
                    'max_storage_mb' => 5000,
                    'trial_ends_at' => null,
                    'subscription_ends_at' => $now->copy()->addYear(),
                    'active' => 1,
                    'settings' => json_encode([
                        'timezone' => 'America/Chicago',
                        'language' => 'en',
                        'currency' => 'USD',
                    ]),
                    'features' => json_encode(['donations', 'events', 'groups', 'messaging']),
                    'primary_color' => '#1E40AF',
                    'secondary_color' => '#10B981',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'address' => [
                    'line1' => '123 Church Street',
                    'district' => 'Davidson County',
                    'city' => 'Nashville',
                    'state_province' => 'Tennessee',
                    'country' => 'USA',
                    'pin_zip_code' => '37201',
                    'country_id' => $usaId,
                    'address_type' => 'official',
                ]
            ],
            [
                'tenant' => [
                    'name' => 'Grace Community Church',
                    'slug' => 'grace-community-church',
                    'plan' => 'basic',
                    'max_users' => 50,
                    'max_storage_mb' => 1000,
                    'trial_ends_at' => $now->copy()->addDays(30),
                    'subscription_ends_at' => null,
                    'active' => 1,
                    'settings' => json_encode([
                        'timezone' => 'America/Chicago',
                        'language' => 'en',
                        'currency' => 'USD',
                    ]),
                    'features' => json_encode(['donations', 'events']),
                    'primary_color' => '#7C3AED',
                    'secondary_color' => '#F59E0B',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'address' => [
                    'line1' => '456 Grace Avenue',
                    'district' => 'Travis County',
                    'city' => 'Austin',
                    'state_province' => 'Texas',
                    'country' => 'USA',
                    'pin_zip_code' => '73301',
                    'country_id' => $usaId,
                    'address_type' => 'official',
                ]
            ],
            [
                'tenant' => [
                    'name' => 'New Life Ministry',
                    'slug' => 'new-life-ministry',
                    'plan' => 'free',
                    'max_users' => 10,
                    'max_storage_mb' => 100,
                    'trial_ends_at' => null,
                    'subscription_ends_at' => null,
                    'active' => 1,
                    'settings' => json_encode([
                        'timezone' => 'America/Chicago',
                        'language' => 'en',
                        'currency' => 'USD',
                    ]),
                    'features' => json_encode(['events']),
                    'primary_color' => '#3B82F6',
                    'secondary_color' => '#10B981',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'address' => [
                    'line1' => '789 Ministry Road',
                    'district' => 'Dallas County',
                    'city' => 'Dallas',
                    'state_province' => 'Texas',
                    'country' => 'USA',
                    'pin_zip_code' => '75201',
                    'country_id' => $usaId,
                    'address_type' => 'official',
                ]
            ],
        ];

        foreach ($tenantsData as $data) {
            // Create tenant
            $tenant = Tenant::create($data['tenant']);
            
            // Create official address for tenant
            $tenant->addresses()->create($data['address']);
        }

        $this->command->info('✅ [Tenants Module] Sample tenants created successfully!');
        $this->command->info('   - First Baptist Church (Premium)');
        $this->command->info('   - Grace Community Church (Basic - Trial)');
        $this->command->info('   - New Life Ministry (Free)');
    }
}

