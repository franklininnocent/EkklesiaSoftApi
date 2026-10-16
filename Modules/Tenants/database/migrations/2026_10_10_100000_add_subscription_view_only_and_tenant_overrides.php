<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: view-only allowance, per-tenant duration overrides, one-time grandfather deadline.
 * Does not modify subscription end dates or delete tenant data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscription_settings') && ! Schema::hasColumn('subscription_settings', 'view_only_allowance_days')) {
            Schema::table('subscription_settings', function (Blueprint $table) {
                $table->unsignedInteger('view_only_allowance_days')->default(90)->after('expiring_warning_days')
                    ->comment('Days after grace ends during which read-only parish access remains');
            });
        }

        if (Schema::hasTable('tenants')) {
            Schema::table('tenants', function (Blueprint $table) {
                if (! Schema::hasColumn('tenants', 'custom_grace_period_days')) {
                    $table->unsignedInteger('custom_grace_period_days')->nullable()->after('subscription_suspended_at');
                }
                if (! Schema::hasColumn('tenants', 'custom_view_only_allowance_days')) {
                    $table->unsignedInteger('custom_view_only_allowance_days')->nullable()->after('custom_grace_period_days');
                }
                if (! Schema::hasColumn('tenants', 'view_only_grandfather_until')) {
                    $table->timestampTz('view_only_grandfather_until')->nullable()->after('custom_view_only_allowance_days');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscription_settings') && Schema::hasColumn('subscription_settings', 'view_only_allowance_days')) {
            Schema::table('subscription_settings', function (Blueprint $table) {
                $table->dropColumn('view_only_allowance_days');
            });
        }

        if (Schema::hasTable('tenants')) {
            Schema::table('tenants', function (Blueprint $table) {
                $columns = array_filter([
                    Schema::hasColumn('tenants', 'view_only_grandfather_until') ? 'view_only_grandfather_until' : null,
                    Schema::hasColumn('tenants', 'custom_view_only_allowance_days') ? 'custom_view_only_allowance_days' : null,
                    Schema::hasColumn('tenants', 'custom_grace_period_days') ? 'custom_grace_period_days' : null,
                ]);
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
