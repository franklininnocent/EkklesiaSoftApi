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
        Schema::create('parish_zones', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary();
            
            // Tenant Relationship
            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            // Zone Information
            $table->string('zone_code', 50)->comment('Unique zone code within tenant');
            $table->string('name', 255)->comment('Zone name');
            $table->text('description')->nullable();
            
            // Geographic Information
            $table->string('area', 255)->nullable()->comment('Geographic area covered');
            $table->text('boundaries')->nullable()->comment('Zone boundaries description');
            
            // Coordinator Information (optional)
            $table->string('coordinator_name', 255)->nullable();
            $table->string('coordinator_phone', 20)->nullable();
            $table->string('coordinator_email', 255)->nullable();
            
            // Status
            $table->boolean('active')->default(true);
            $table->integer('display_order')->default(0)->comment('Order for display');
            
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
            $table->index('active');
            $table->unique(['tenant_id', 'zone_code']); // Unique zone code per tenant
            $table->unique(['tenant_id', 'name']); // Unique zone name per tenant
        });
        
        // Note: Parish zones have been removed from the application
        // Foreign key constraints for parish_zone_id have been removed from families and bccs tables
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parish_zones');
    }
};
