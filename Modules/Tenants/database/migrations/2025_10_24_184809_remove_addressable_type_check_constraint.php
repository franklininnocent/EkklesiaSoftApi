<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Remove the check constraint on addressable_type to allow flexibility
     * with module namespaces. The polymorphic relationship is enforced at
     * the application level through Eloquent models.
     */
    public function up(): void
    {
        // Drop the check constraint entirely
        DB::statement('ALTER TABLE addresses DROP CONSTRAINT IF EXISTS check_addressable_type');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore a basic constraint (allowing both short and long names)
        // This is a best-effort restoration - adjust based on your needs
        DB::statement("
            ALTER TABLE addresses 
            ADD CONSTRAINT check_addressable_type 
            CHECK (addressable_type IS NOT NULL AND addressable_type != '')
        ");
    }
};
