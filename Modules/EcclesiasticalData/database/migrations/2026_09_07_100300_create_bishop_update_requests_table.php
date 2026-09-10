<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bishop_update_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('diocese_id');
            $table->unsignedBigInteger('target_bishop_id')->nullable();
            $table->string('request_type', 50);
            $table->json('proposed_bishop_data')->nullable();
            $table->json('proposed_appointment_data')->nullable();
            $table->text('supporting_information')->nullable();
            $table->string('source_reference', 500)->nullable();
            $table->text('submission_notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->text('reviewer_comments')->nullable();
            $table->text('internal_reviewer_notes')->nullable();
            $table->text('submitter_feedback')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('diocese_id')->references('id')->on('archdioceses')->cascadeOnDelete();
            $table->foreign('target_bishop_id')->references('id')->on('bishops')->nullOnDelete();
            $table->foreign('submitted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewer_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'status']);
            $table->index(['diocese_id', 'status']);
            $table->index(['status', 'submitted_at']);
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bishop_update_requests');
    }
};
