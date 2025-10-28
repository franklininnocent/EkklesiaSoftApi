<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - Create audit and management tables for ecclesiastical data
     */
    public function up(): void
    {
        // Ecclesiastical Data Audit Log - tracks all changes to master data
        Schema::create('ecclesiastical_audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 100)->index(); // e.g., 'diocese', 'bishop', 'denomination'
            $table->string('entity_id')->index(); // String to support both UUID and integer IDs
            $table->string('action', 50); // create, update, delete, restore
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('changes')->nullable(); // specific fields changed
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at');
            
            $table->index(['entity_type', 'entity_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });

        // Diocese Hierarchy Relationships - manages province/suffragan relationships
        Schema::create('diocese_hierarchy', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('metropolitan_id'); // The Archdiocese
            $table->unsignedBigInteger('suffragan_id'); // The Diocese
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('metropolitan_id')->references('id')->on('archdioceses')->onDelete('cascade');
            $table->foreign('suffragan_id')->references('id')->on('archdioceses')->onDelete('cascade');
            $table->unique(['metropolitan_id', 'suffragan_id', 'effective_from'], 'unique_hierarchy');
            $table->index(['metropolitan_id', 'is_active']);
            $table->index(['suffragan_id', 'is_active']);
        });

        // Bishop Appointments - historical record of all episcopal appointments
        Schema::create('bishop_appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('bishop_id');
            $table->unsignedBigInteger('diocese_id');
            $table->unsignedBigInteger('ecclesiastical_title_id');
            $table->date('appointed_date');
            $table->date('ordained_date')->nullable();
            $table->date('installed_date')->nullable();
            $table->date('ended_date')->nullable();
            $table->string('end_reason', 100)->nullable(); // retirement, transfer, death, resignation
            $table->boolean('is_current')->default(true)->index();
            $table->text('appointment_details')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('bishop_id')->references('id')->on('bishops')->onDelete('cascade');
            $table->foreign('diocese_id')->references('id')->on('archdioceses')->onDelete('cascade');
            $table->foreign('ecclesiastical_title_id')->references('id')->on('ecclesiastical_titles')->onDelete('restrict');
            $table->index(['bishop_id', 'is_current']);
            $table->index(['diocese_id', 'is_current']);
            $table->index('appointed_date');
        });

        // Bulk Import Jobs - tracks import operations
        Schema::create('ecclesiastical_import_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 100); // diocese, bishop, denomination
            $table->string('file_name');
            $table->string('file_path');
            $table->string('status', 50)->default('pending'); // pending, processing, completed, failed
            $table->integer('total_records')->default(0);
            $table->integer('processed_records')->default(0);
            $table->integer('successful_records')->default(0);
            $table->integer('failed_records')->default(0);
            $table->json('errors')->nullable();
            $table->json('summary')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index(['entity_type', 'status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // Data Versioning - maintains version history for critical records
        Schema::create('ecclesiastical_data_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 100)->index();
            $table->uuid('entity_id')->index();
            $table->integer('version_number')->default(1);
            $table->json('data_snapshot'); // Full record snapshot
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('change_reason')->nullable();
            $table->timestamp('created_at');
            
            $table->index(['entity_type', 'entity_id', 'version_number']);
            $table->unique(['entity_type', 'entity_id', 'version_number'], 'unique_version');
        });

        // Permissions for ecclesiastical data management
        Schema::create('ecclesiastical_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->string('category', 50)->index(); // diocese, bishop, denomination, system
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        // Data Quality Flags - track data completeness and quality issues
        Schema::create('ecclesiastical_data_quality', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 100);
            $table->uuid('entity_id');
            $table->string('quality_flag', 50); // incomplete, needs_review, verified, deprecated
            $table->string('severity', 20)->default('info'); // info, warning, error, critical
            $table->text('issue_description');
            $table->text('recommended_action')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->foreignId('flagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('flagged_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            
            $table->index(['entity_type', 'entity_id', 'is_resolved']);
            $table->index(['quality_flag', 'is_resolved']);
            $table->index('severity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecclesiastical_data_quality');
        Schema::dropIfExists('ecclesiastical_permissions');
        Schema::dropIfExists('ecclesiastical_data_versions');
        Schema::dropIfExists('ecclesiastical_import_jobs');
        Schema::dropIfExists('bishop_appointments');
        Schema::dropIfExists('diocese_hierarchy');
        Schema::dropIfExists('ecclesiastical_audit_log');
    }
};
