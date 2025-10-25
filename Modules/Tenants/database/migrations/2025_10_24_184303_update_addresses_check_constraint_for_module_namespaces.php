<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration updates the check constraint on addresses table to allow
     * fully qualified module namespaces like 'Modules\Authentication\Models\User'
     * instead of just 'User', 'Tenant', 'Organization'.
     */
    public function up(): void
    {
        // Drop the old check constraint
        DB::statement('ALTER TABLE addresses DROP CONSTRAINT IF EXISTS check_addressable_type');
        
        // Add new check constraint that allows module namespaces
        DB::statement("
            ALTER TABLE addresses 
            ADD CONSTRAINT check_addressable_type 
            CHECK (addressable_type IN (
                'User',
                'Tenant', 
                'Organization',
                'Modules\\\\Authentication\\\\Models\\\\User',
                'Modules\\\\Tenants\\\\Models\\\\Tenant',
                'Modules\\\\Tenants\\\\Models\\\\Organization'
            ))
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the new constraint
        DB::statement('ALTER TABLE addresses DROP CONSTRAINT IF EXISTS check_addressable_type');
        
        // Restore the old constraint (short names only)
        DB::statement("
            ALTER TABLE addresses 
            ADD CONSTRAINT check_addressable_type 
            CHECK (addressable_type IN ('User', 'Tenant', 'Organization'))
        ");
    }
};
