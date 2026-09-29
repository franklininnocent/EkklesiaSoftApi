<?php

use Illuminate\Database\Migrations\Migration;
use Modules\ApplicationAccess\Support\ApplicationAccessPermissionCatalog;
use Modules\Authentication\Models\User;

return new class extends Migration
{
    public function up(): void
    {
        ApplicationAccessPermissionCatalog::syncPermissionsAndRoles();
        User::flushRequestPermissionCache();
    }

    public function down(): void
    {
        // Permissions remain on rollback — safe for production; revoke manually if needed.
    }
};
