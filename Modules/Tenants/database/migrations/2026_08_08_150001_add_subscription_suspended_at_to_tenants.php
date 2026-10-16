<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'subscription_suspended_at')) {
                $table->timestamp('subscription_suspended_at')->nullable()->after('subscription_ends_at')
                    ->comment('When set, subscription status is SUSPENDED (admin soft-block)');
                $table->index('subscription_suspended_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'subscription_suspended_at')) {
                $table->dropIndex(['subscription_suspended_at']);
                $table->dropColumn('subscription_suspended_at');
            }
        });
    }
};
