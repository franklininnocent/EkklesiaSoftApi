<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Modules\Tenants\Models\SubscriptionPlan;

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
                'features' => ['events', 'ministries_associations'],
                'display_order' => 1,
                'active' => true,
                'is_default' => true,
            ],
            [
                'key' => 'enterprise',
                'name' => 'Enterprise Plan',
                'description' => 'Full-featured solution with unlimited users and premium support',
                'price' => 299.99,
                'max_users' => 999999,
                'max_storage_mb' => 50000,
                'features' => ['events', 'donations', 'groups', 'messaging', 'custom_branding', 'api_access', 'dedicated_support', 'ministries_associations'],
                'display_order' => 2,
                'active' => true,
                'is_default' => false,
            ],
        ];

        // Once the versioned catalog (Subscriptions module) owns plans, legacy seed rows are not
        // needed and existing rows must never be overwritten (Super Admin edits, grandfathering).
        if (Schema::hasColumn('subscription_plans', 'code')
            && SubscriptionPlan::withTrashed()->whereNotNull('code')->exists()) {
            return;
        }

        foreach ($plans as $plan) {
            if (SubscriptionPlan::withTrashed()->where('key', $plan['key'])->exists()) {
                continue;
            }
            SubscriptionPlan::query()->create($plan);
        }
    }
}
