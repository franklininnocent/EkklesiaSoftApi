<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('kind', 40);
            $table->string('period', 40);
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'kind', 'period']);
        });

        Schema::create('donation_idempotency_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('operation', 80);
            $table->string('key', 80);
            $table->string('payload_hash', 64);
            $table->string('resource_type', 40)->nullable();
            $table->string('resource_id', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->default(201);
            $table->json('response_body')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'operation', 'key']);
        });

        Schema::create('donation_security_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event', 80);
            $table->string('method', 12)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('target_type', 60)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'event']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::table('donation_payments', function (Blueprint $table) {
            $table->string('idempotency_key', 80)->nullable()->after('notes');
            $table->decimal('refunded_amount', 14, 2)->default(0)->after('amount');
        });

        Schema::table('donation_receipts', function (Blueprint $table) {
            $table->json('snapshot')->nullable()->after('void_reason');
            $table->uuid('replaces_receipt_id')->nullable()->after('snapshot');
            $table->uuid('replaced_by_receipt_id')->nullable()->after('replaces_receipt_id');
            $table->timestamp('voided_at')->nullable()->after('replaced_by_receipt_id');
            $table->unsignedBigInteger('voided_by')->nullable()->after('voided_at');
        });

        Schema::table('donation_audit_logs', function (Blueprint $table) {
            $table->string('request_id', 64)->nullable()->after('support_session_id');
            $table->string('idempotency_key', 80)->nullable()->after('request_id');
        });

        $this->createUniqueIndex(
            'donation_payments_tenant_gateway_ref_unique',
            'CREATE UNIQUE INDEX donation_payments_tenant_gateway_ref_unique ON donation_payments (tenant_id, gateway_reference) WHERE gateway_reference IS NOT NULL AND gateway_reference != \'\' AND deleted_at IS NULL'
        );

        $voidPredicate = Schema::getConnection()->getDriverName() === 'pgsql'
            ? 'is_void = false'
            : 'is_void = 0';

        $this->createUniqueIndex(
            'donation_receipts_one_active_per_payment',
            "CREATE UNIQUE INDEX donation_receipts_one_active_per_payment ON donation_receipts (payment_id) WHERE {$voidPredicate} AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        $this->dropIndexIfExists('donation_receipts_one_active_per_payment');
        $this->dropIndexIfExists('donation_payments_tenant_gateway_ref_unique');

        Schema::table('donation_audit_logs', function (Blueprint $table) {
            $table->dropColumn(['request_id', 'idempotency_key']);
        });

        Schema::table('donation_receipts', function (Blueprint $table) {
            $table->dropColumn([
                'snapshot',
                'replaces_receipt_id',
                'replaced_by_receipt_id',
                'voided_at',
                'voided_by',
            ]);
        });

        Schema::table('donation_payments', function (Blueprint $table) {
            $table->dropColumn(['idempotency_key', 'refunded_amount']);
        });

        Schema::dropIfExists('donation_security_events');
        Schema::dropIfExists('donation_idempotency_records');
        Schema::dropIfExists('donation_number_sequences');
    }

    private function createUniqueIndex(string $name, string $sql): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            Schema::table('donation_payments', function (Blueprint $table) use ($name): void {
                if ($name === 'donation_payments_tenant_gateway_ref_unique') {
                    $table->unique(['tenant_id', 'gateway_reference'], $name);
                }
            });

            return;
        }

        DB::statement($sql);
    }

    private function dropIndexIfExists(string $name): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS {$name}");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
