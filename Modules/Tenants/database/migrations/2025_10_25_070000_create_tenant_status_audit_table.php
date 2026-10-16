<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates a normalized audit table for tracking all tenant status changes.
     * This table maintains a complete history of when tenants were activated/deactivated,
     * who made the change, why, and from where.
     */
    public function up(): void
    {
        Schema::create('tenant_status_audit', function (Blueprint $table) {
            // Primary Key
            $table->id();
            
            // Tenant Reference
            $table->unsignedBigInteger('tenant_id')
                ->comment('Reference to the tenant whose status was changed');
            
            // Status Change Details
            $table->integer('previous_status')
                ->comment('Previous status: 0=Inactive, 1=Active');
            
            $table->integer('new_status')
                ->comment('New status: 0=Inactive, 1=Active');
            
            $table->string('action', 20)
                ->comment('Action performed: activated, deactivated');
            
            // Reason for Change
            $table->text('reason')
                ->nullable()
                ->comment('Optional reason provided by the user');
            
            // User Who Performed the Action
            $table->unsignedBigInteger('performed_by')
                ->comment('ID of the user who performed the status change');
            
            // Security & Tracking Information
            $table->string('ip_address', 45)
                ->nullable()
                ->comment('IP address from which the action was performed (IPv4 or IPv6)');
            
            $table->text('user_agent')
                ->nullable()
                ->comment('Browser/client user agent string');
            
            // Additional Metadata (JSON for extensibility)
            $table->json('metadata')
                ->nullable()
                ->comment('Additional contextual data (e.g., request details, session info)');
            
            // Timestamp
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('When the status change occurred');
            
            // Foreign Key Constraints
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade')
                ->onUpdate('cascade');
            
            $table->foreign('performed_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict') // Don't allow deleting users with audit history
                ->onUpdate('cascade');
            
            // Indexes for Performance
            $table->index('tenant_id', 'idx_tenant_status_audit_tenant');
            $table->index('performed_by', 'idx_tenant_status_audit_user');
            $table->index('created_at', 'idx_tenant_status_audit_created');
            $table->index(['tenant_id', 'created_at'], 'idx_tenant_status_audit_tenant_date');
            $table->index('action', 'idx_tenant_status_audit_action');
            
            // Table Comment
            $table->comment('Audit trail for all tenant status changes (activation/deactivation)');
        });
        
        // Add check constraints for data integrity (PostgreSQL)
        if (config('database.default') === 'pgsql') {
            DB::statement('ALTER TABLE tenant_status_audit ADD CONSTRAINT chk_previous_status CHECK (previous_status IN (0, 1))');
            DB::statement('ALTER TABLE tenant_status_audit ADD CONSTRAINT chk_new_status CHECK (new_status IN (0, 1))');
            DB::statement('ALTER TABLE tenant_status_audit ADD CONSTRAINT chk_action CHECK (action IN (\'activated\', \'deactivated\'))');
            DB::statement('ALTER TABLE tenant_status_audit ADD CONSTRAINT chk_status_changed CHECK (previous_status != new_status)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_status_audit');
    }
};

