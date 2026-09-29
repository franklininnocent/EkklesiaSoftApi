<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mass_intention_settings', function (Blueprint $table) {
            $table->boolean('provincial_collective_authorized')->default(false)->after('review_days');
        });

        Schema::table('mass_intention_requests', function (Blueprint $table) {
            $table->boolean('prohibit_transfer')->default(false)->after('date_must_be_kept');
            $table->boolean('is_collective')->default(false)->after('prohibit_transfer');
        });

        Schema::create('mass_intention_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('event_type', 64);
            $table->uuid('request_id')->nullable();
            $table->uuid('celebration_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('request_id')->references('id')->on('mass_intention_requests')->nullOnDelete();
            $table->foreign('celebration_id')->references('id')->on('mass_celebrations')->nullOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'event_type']);
            $table->index(['tenant_id', 'request_id']);
        });

        Schema::create('mass_intention_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('from_tenant_id');
            $table->unsignedBigInteger('to_tenant_id');
            $table->uuid('request_id');
            $table->string('status', 32)->default('pending');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('initiated_by_user_id');
            $table->unsignedBigInteger('responded_by_user_id')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->foreign('from_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('to_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('request_id')->references('id')->on('mass_intention_requests')->cascadeOnDelete();
            $table->foreign('initiated_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('responded_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['to_tenant_id', 'status']);
            $table->index(['from_tenant_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mass_intention_transfers');
        Schema::dropIfExists('mass_intention_audits');

        Schema::table('mass_intention_requests', function (Blueprint $table) {
            $table->dropColumn(['prohibit_transfer', 'is_collective']);
        });

        Schema::table('mass_intention_settings', function (Blueprint $table) {
            $table->dropColumn('provincial_collective_authorized');
        });
    }
};
