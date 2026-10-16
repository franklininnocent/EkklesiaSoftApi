<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_member_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->uuid('transition_id')->nullable();
            $table->uuid('member_id');
            $table->foreign('member_id')->references('id')->on('family_members')->restrictOnDelete();

            $table->uuid('from_family_id')->nullable();
            $table->foreign('from_family_id')->references('id')->on('families')->restrictOnDelete();

            $table->uuid('to_family_id')->nullable();
            $table->foreign('to_family_id')->references('id')->on('families')->restrictOnDelete();

            $table->string('previous_family_role', 40)->nullable();
            $table->string('new_family_role', 40)->nullable();
            $table->string('transition_type', 40);
            $table->date('effective_date');
            $table->unsignedBigInteger('performed_by_user_id')->nullable();
            $table->uuid('corrects_history_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('performed_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'member_id']);
            $table->index(['tenant_id', 'from_family_id']);
            $table->index(['tenant_id', 'to_family_id']);
            $table->index(['tenant_id', 'transition_id']);
        });

        // Self-referential FK must be added after the table (and its PK) exists — PostgreSQL rejects it inline.
        Schema::table('family_member_histories', function (Blueprint $table) {
            $table->foreign('corrects_history_id')
                ->references('id')
                ->on('family_member_histories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('family_member_histories', function (Blueprint $table) {
            $table->dropForeign(['corrects_history_id']);
        });

        Schema::dropIfExists('family_member_histories');
    }
};
