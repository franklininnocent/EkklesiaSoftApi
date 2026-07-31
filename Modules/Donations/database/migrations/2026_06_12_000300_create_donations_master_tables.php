<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('default_currency', 10)->default('INR');
            $table->string('financial_year_start_month', 2)->default('01');
            $table->string('financial_year_start_day', 2)->default('01');
            $table->string('tax_registration_number', 120)->nullable();
            $table->boolean('receipt_prefix_enabled')->default(true);
            $table->string('receipt_prefix', 20)->default('RCPT');
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id']);
            $table->index('tenant_id');
        });

        Schema::create('donation_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 160);
            $table->string('code', 60);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('contribution_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 160);
            $table->string('code', 60);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('donors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('family_id')->nullable();
            $table->uuid('family_member_id')->nullable();
            $table->string('name', 180);
            $table->string('email', 180)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('donor_type', 40)->default('family');
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'family_id']);
            $table->index(['tenant_id', 'email']);
        });

        Schema::create('donations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('donor_id')->nullable();
            $table->uuid('family_id')->nullable();
            $table->uuid('family_member_id')->nullable();
            $table->uuid('donation_category_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->string('title', 180)->nullable();
            $table->decimal('pledged_amount', 14, 2)->default(0);
            $table->decimal('collected_amount', 14, 2)->default(0);
            $table->date('received_at')->nullable();
            $table->string('financial_year', 20)->nullable();
            $table->enum('status', ['pledged', 'partially_paid', 'paid', 'cancelled'])->default('pledged');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'received_at']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'financial_year']);
            $table->index(['tenant_id', 'family_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
        Schema::dropIfExists('donors');
        Schema::dropIfExists('contribution_categories');
        Schema::dropIfExists('donation_categories');
        Schema::dropIfExists('donation_settings');
    }
};
