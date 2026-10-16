<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_recovery_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('email_hmac', 64);
            $table->string('otp_verifier', 64);
            $table->string('reset_authorization_verifier', 64)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->unsignedSmallInteger('resend_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('reset_authorization_expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamp('blocked_until')->nullable();
            $table->timestamp('last_resend_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
            $table->index(['email_hmac', 'created_at']);
        });

        Schema::create('password_recovery_throttles', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->date('window_date');
            $table->unsignedSmallInteger('initiation_count')->default(0);
            $table->timestamp('blocked_until')->nullable();
            $table->timestamp('updated_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS users_email_lower_index ON users (LOWER(email))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS users_email_lower_index');
        }

        Schema::dropIfExists('password_recovery_throttles');
        Schema::dropIfExists('password_recovery_challenges');
    }
};
