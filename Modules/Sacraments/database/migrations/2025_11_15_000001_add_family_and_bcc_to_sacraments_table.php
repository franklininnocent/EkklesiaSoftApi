<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds family_id and bcc_id to sacraments table to link sacraments
     * to families and BCCs for better organization and reporting.
     */
    public function up(): void
    {
        Schema::table('sacraments', function (Blueprint $table) {
            // Add family_id column (nullable - not all sacraments may be linked to families)
            $table->uuid('family_id')->nullable()->after('tenant_id')
                ->comment('Family ID - links sacrament to a family record');
            
            // Add bcc_id column (nullable - not all sacraments may be linked to BCCs)
            $table->uuid('bcc_id')->nullable()->after('family_id')
                ->comment('BCC ID - links sacrament to a Basic Christian Community');
            
            // Add foreign key constraints
            $table->foreign('family_id')
                ->references('id')
                ->on('families')
                ->onDelete('set null')
                ->comment('Foreign key to families table');
            
            $table->foreign('bcc_id')
                ->references('id')
                ->on('bccs')
                ->onDelete('set null')
                ->comment('Foreign key to bccs table');
            
            // Add indexes for better query performance
            $table->index('family_id');
            $table->index('bcc_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sacraments', function (Blueprint $table) {
            // Drop foreign keys first
            $table->dropForeign(['family_id']);
            $table->dropForeign(['bcc_id']);
            
            // Drop indexes
            $table->dropIndex(['family_id']);
            $table->dropIndex(['bcc_id']);
            
            // Drop columns
            $table->dropColumn(['family_id', 'bcc_id']);
        });
    }
};

