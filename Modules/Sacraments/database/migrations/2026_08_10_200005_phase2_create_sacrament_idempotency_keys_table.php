<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — sacrament_idempotency_keys (§5.6). Wired in Phase 3 create/batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sacrament_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 128)->nullable();
            $table->foreignId('sacrament_id')->nullable()->constrained('sacraments')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'idempotency_key'], 'sacrament_idempotency_keys_tenant_key_unique');
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sacrament_idempotency_keys');
    }
};
