<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ma_organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->uuid('category_id');
            $table->foreign('category_id')->references('id')->on('ma_organization_categories')->restrictOnDelete();

            $table->uuid('type_id');
            $table->foreign('type_id')->references('id')->on('ma_organization_types')->restrictOnDelete();

            $table->string('code', 50);
            $table->string('name', 255);
            $table->string('short_name', 50)->nullable();
            $table->text('description')->nullable();
            $table->text('vision')->nullable();
            $table->text('mission')->nullable();
            $table->text('objectives')->nullable();
            $table->string('patron_saint', 150)->nullable();
            $table->date('established_date')->nullable();
            $table->char('theme_color', 7)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('website', 500)->nullable();
            $table->json('social_links')->nullable();
            $table->string('status', 20)->default('active');
            $table->boolean('allow_multi_role_holding')->default(false);
            $table->boolean('guests_can_hold_office')->default(false);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'category_id']);
            $table->index(['tenant_id', 'type_id']);
            $table->index(['tenant_id', 'established_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ma_organizations');
    }
};
