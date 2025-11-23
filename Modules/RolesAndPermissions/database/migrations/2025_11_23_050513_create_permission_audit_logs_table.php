<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates table for storing permission and role change audit logs.
     */
    public function up(): void
    {
        Schema::create('permission_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 100); // e.g., 'permission_assigned_to_role', 'role_created'
            $table->uuid('permission_id')->nullable();
            $table->uuid('role_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->uuid('assigned_by')->nullable(); // User who performed the action
            $table->integer('tenant_id')->nullable();
            $table->json('metadata')->nullable(); // Additional data about the action
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            // Indexes for efficient querying
            $table->index('action');
            $table->index('permission_id');
            $table->index('role_id');
            $table->index('user_id');
            $table->index('assigned_by');
            $table->index('tenant_id');
            $table->index('created_at');
            
            // Composite indexes for common queries
            $table->index(['action', 'tenant_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['role_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permission_audit_logs');
    }
};
