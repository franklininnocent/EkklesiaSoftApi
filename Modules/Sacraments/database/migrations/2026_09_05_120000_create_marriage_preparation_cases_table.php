<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marriage_preparation_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('bcc_id')->nullable();
            $table->uuid('bride_family_member_id')->nullable();
            $table->uuid('groom_family_member_id')->nullable();
            $table->unsignedBigInteger('sacrament_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('inquiry_started_at');
            $table->timestamp('pre_cana_completed_at')->nullable();
            $table->timestamp('banns_published_at')->nullable();
            $table->timestamp('canonical_docs_verified_at')->nullable();
            $table->date('intended_marriage_date')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('bcc_id')->references('id')->on('bccs')->nullOnDelete();
            $table->foreign('bride_family_member_id')->references('id')->on('family_members')->nullOnDelete();
            $table->foreign('groom_family_member_id')->references('id')->on('family_members')->nullOnDelete();
            $table->foreign('sacrament_id')->references('id')->on('sacraments')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'inquiry_started_at']);
            $table->index(['tenant_id', 'bcc_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marriage_preparation_cases');
    }
};
