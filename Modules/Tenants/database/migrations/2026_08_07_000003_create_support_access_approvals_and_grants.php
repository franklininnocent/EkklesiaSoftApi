<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 MVP: emergency approval requests + customer-granted access windows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_access_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('requester_user_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('mode', 32); // emergency (MVP)
            $table->string('reason_code', 64);
            $table->text('reason_description')->nullable();
            $table->string('ticket_ref', 128)->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestampTz('requested_at');
            $table->timestampTz('expires_at');
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->uuid('consumed_session_id')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'expires_at']);
            $table->index(['requester_user_id', 'status']);
            $table->index(['tenant_id', 'status']);

            $table->foreign('requester_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('decided_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('support_access_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('granted_by_user_id');
            $table->string('allowed_mode', 32); // readonly|standard|emergency|any
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 32)->default('active');
            $table->text('note')->nullable();
            $table->unsignedInteger('max_sessions')->nullable();
            $table->unsignedInteger('sessions_used')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'status', 'ends_at']);
            $table->index(['status', 'ends_at']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('granted_by_user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('support_sessions', function (Blueprint $table) {
            $table->uuid('approval_request_id')->nullable()->after('user_agent');
            $table->uuid('access_grant_id')->nullable()->after('approval_request_id');

            $table->foreign('approval_request_id')
                ->references('id')
                ->on('support_access_requests')
                ->nullOnDelete();
            $table->foreign('access_grant_id')
                ->references('id')
                ->on('support_access_grants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('support_sessions', function (Blueprint $table) {
            $table->dropForeign(['approval_request_id']);
            $table->dropForeign(['access_grant_id']);
            $table->dropColumn(['approval_request_id', 'access_grant_id']);
        });

        Schema::dropIfExists('support_access_grants');
        Schema::dropIfExists('support_access_requests');
    }
};
