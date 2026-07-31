<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bccs', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary();
            
            // Tenant Relationship
            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            // BCC Identification
            $table->string('bcc_code', 50)->comment('Unique BCC code within tenant');
            $table->string('name', 255)->comment('BCC name');
            $table->text('description')->nullable();
            
            // Location Information
            $table->string('meeting_place', 255)->nullable()->comment('Regular meeting location');
            
            // Meeting Schedule
            $table->enum('meeting_day', [
                'monday', 'tuesday', 'wednesday', 'thursday', 
                'friday', 'saturday', 'sunday'
            ])->nullable();
            $table->time('meeting_time')->nullable();
            $table->string('meeting_frequency', 100)->nullable()->comment('e.g., Weekly, Bi-weekly, Monthly');
            
            // Family Capacity
            $table->integer('min_families')->default(10)->comment('Minimum number of families');
            $table->integer('max_families')->default(50)->comment('Maximum number of families');
            // Note: current_family_count removed - it's calculated data (COUNT from families table)
            
            // Contact Information
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email', 255)->nullable();
            
            // Status and Metadata
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->date('established_date')->nullable()->comment('Date BCC was established');
            $table->text('notes')->nullable();
            
            // Audit Fields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign Keys
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
            
            // Indexes
            $table->index('tenant_id');
            $table->index('status');
            $table->unique(['tenant_id', 'bcc_code']); // Unique BCC code per tenant
            $table->unique(['tenant_id', 'name']); // Unique BCC name per tenant
        });
        
        // Now add the foreign key to families table
        Schema::table('families', function (Blueprint $table) {
            $table->foreign('bcc_id')->references('id')->on('bccs')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop foreign key from families first
        Schema::table('families', function (Blueprint $table) {
            $table->dropForeign(['bcc_id']);
        });
        
        Schema::dropIfExists('bccs');
    }
};
