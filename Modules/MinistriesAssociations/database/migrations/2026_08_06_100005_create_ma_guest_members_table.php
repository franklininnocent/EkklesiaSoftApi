<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ma_guest_members', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('gender', 10)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('address')->nullable();
            $table->string('guest_type', 30)->default('supporter');
            $table->string('external_organization', 255)->nullable();
            $table->string('support_type', 20)->nullable();
            $table->text('remarks')->nullable();

            $table->uuid('linked_family_member_id')->nullable();
            $table->foreign('linked_family_member_id')->references('id')->on('family_members')->nullOnDelete();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'last_name', 'first_name']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX ma_guest_members_tenant_phone_idx ON ma_guest_members (tenant_id, phone) WHERE phone IS NOT NULL');
            DB::statement('CREATE INDEX ma_guest_members_tenant_email_idx ON ma_guest_members (tenant_id, email) WHERE email IS NOT NULL');
            DB::statement('CREATE INDEX ma_guest_members_linked_family_member_idx ON ma_guest_members (linked_family_member_id) WHERE linked_family_member_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ma_guest_members_tenant_phone_idx');
            DB::statement('DROP INDEX IF EXISTS ma_guest_members_tenant_email_idx');
            DB::statement('DROP INDEX IF EXISTS ma_guest_members_linked_family_member_idx');
        }

        Schema::dropIfExists('ma_guest_members');
    }
};
