<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_recovery_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('requester_email', 255);
            $table->string('requester_domain', 20);
            $table->string('requester_classification', 30);
            $table->unsignedBigInteger('intended_approver_user_id')->nullable();
            $table->unsignedBigInteger('processed_by_user_id')->nullable();
            $table->string('status', 30)->default('pending_approval');
            $table->string('request_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('failure_reason', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('password_committed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->foreign('intended_approver_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('processed_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['status', 'expires_at']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index(['intended_approver_user_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX password_recovery_requests_user_inflight_unique
                 ON password_recovery_requests (user_id)
                 WHERE status IN ('pending_approval', 'processing')"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS password_recovery_requests_user_inflight_unique');
        }

        Schema::dropIfExists('password_recovery_requests');
    }
};
