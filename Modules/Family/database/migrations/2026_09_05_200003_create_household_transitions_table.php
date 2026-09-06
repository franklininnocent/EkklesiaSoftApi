<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->uuid('transition_id');
            $table->string('type', 40);
            $table->string('status', 20)->default('completed');
            $table->string('request_hash', 64)->nullable();
            $table->json('result_summary')->nullable();
            $table->unsignedBigInteger('performed_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('performed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'transition_id']);
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_transitions');
    }
};
