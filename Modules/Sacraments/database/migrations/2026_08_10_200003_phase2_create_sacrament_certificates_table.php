<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — sacrament_certificates (§5.4 minimal). Issuance in Phase 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sacrament_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sacrament_id')->constrained('sacraments')->cascadeOnDelete();

            $table->string('certificate_number')->nullable();
            $table->string('certificate_type', 80)->nullable();
            $table->string('status', 30)->default('draft_preview');
            $table->unsignedInteger('version')->default(1);

            $table->string('language', 16)->nullable();
            $table->string('locale', 32)->nullable();
            $table->string('template_code', 80)->nullable();
            $table->string('template_version', 40)->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('storage_key')->nullable();
            $table->string('checksum', 128)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->json('projection_json')->nullable();

            $table->unsignedBigInteger('supersedes_certificate_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('supersedes_certificate_id')
                ->references('id')->on('sacrament_certificates')
                ->nullOnDelete();

            $table->index(['tenant_id', 'sacrament_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['sacrament_id', 'version']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE sacrament_certificates
                ADD CONSTRAINT sacrament_certificates_status_check
                CHECK (status IN ('draft_preview','issued','superseded','voided'))
            ");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sacrament_certificates DROP CONSTRAINT IF EXISTS sacrament_certificates_status_check');
        }

        Schema::dropIfExists('sacrament_certificates');
    }
};
