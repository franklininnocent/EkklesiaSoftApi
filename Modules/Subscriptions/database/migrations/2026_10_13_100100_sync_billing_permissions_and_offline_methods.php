<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Support\SubscriptionsPermissionCatalog;

return new class extends Migration
{
    public function up(): void
    {
        SubscriptionsPermissionCatalog::syncPermissionsAndRoles();

        $now = now();
        $methods = [
            ['code' => 'bank_transfer', 'label' => 'Bank transfer', 'sort_order' => 10],
            ['code' => 'neft', 'label' => 'NEFT', 'sort_order' => 20],
            ['code' => 'rtgs', 'label' => 'RTGS', 'sort_order' => 30],
            ['code' => 'imps', 'label' => 'IMPS', 'sort_order' => 40],
            ['code' => 'upi_direct', 'label' => 'UPI (direct)', 'sort_order' => 50],
            ['code' => 'cheque', 'label' => 'Cheque', 'sort_order' => 60],
        ];

        foreach ($methods as $method) {
            DB::table('billing_offline_methods')->updateOrInsert(
                ['code' => $method['code']],
                [
                    'label' => $method['label'],
                    'enabled' => false,
                    'instructions' => null,
                    'sort_order' => $method['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        foreach (['test', 'live'] as $env) {
            DB::table('billing_gateway_configs')->updateOrInsert(
                ['environment' => $env],
                [
                    'enabled' => false,
                    'live_checkout_enabled' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        // Permissions are not removed on rollback.
    }
};
