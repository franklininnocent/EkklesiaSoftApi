<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Tenants\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;

class SubscriptionPlansSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'key' => 'free',
                'name' => 'Free Plan',
                'description' => 'Perfect for small churches getting started',
                'price' => 0.00,
                'max_users' => 10,
                'max_storage_mb' => 100,
                'features' => ['events'],
                'display_order' => 1,
                'active' => true,
                'is_default' => true,
            ],
            [
                'key' => 'basic',
                'name' => 'Basic Plan',
                'description' => 'Ideal for growing churches with moderate needs',
                'price' => 29.99,
                'max_users' => 50,
                'max_storage_mb' => 1000,
                'features' => ['events', 'donations'],
                'display_order' => 2,
                'active' => true,
                'is_default' => false,
            ],
            [
                'key' => 'premium',
                'name' => 'Premium Plan',
                'description' => 'Comprehensive solution for established churches',
                'price' => 99.99,
                'max_users' => 100,
                'max_storage_mb' => 5000,
                'features' => ['events', 'donations', 'groups', 'messaging'],
                'display_order' => 3,
                'active' => true,
                'is_default' => false,
            ],
            [
                'key' => 'enterprise',
                'name' => 'Enterprise Plan',
                'description' => 'Full-featured solution with unlimited users and premium support',
                'price' => 299.99,
                'max_users' => 999999,
                'max_storage_mb' => 50000,
                'features' => ['events', 'donations', 'groups', 'messaging', 'custom_branding', 'api_access', 'dedicated_support'],
                'display_order' => 4,
                'active' => true,
                'is_default' => false,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(
                ['key' => $plan['key']],
                $plan
            );
        }
    }
}

