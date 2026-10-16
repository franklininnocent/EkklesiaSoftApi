<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Support\SubscriptionsPermissionCatalog;

return new class extends Migration
{
    public function up(): void
    {
        SubscriptionsPermissionCatalog::syncPermissionsAndRoles();
        User::flushRequestPermissionCache();
    }

    public function down(): void
    {
        // Permission catalog is additive; no rollback.
    }
};
