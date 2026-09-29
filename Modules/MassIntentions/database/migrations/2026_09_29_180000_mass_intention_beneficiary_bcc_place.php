<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mass_intention_requests', function (Blueprint $table) {
            $table->uuid('beneficiary_bcc_id')->nullable()->after('beneficiary_name');
            $table->string('beneficiary_bcc_name', 255)->nullable()->after('beneficiary_bcc_id');
            $table->string('beneficiary_place', 255)->nullable()->after('beneficiary_bcc_name');

            $table->foreign('beneficiary_bcc_id')->references('id')->on('bccs')->nullOnDelete();
            $table->index(['tenant_id', 'beneficiary_bcc_id']);
        });
    }

    public function down(): void
    {
        Schema::table('mass_intention_requests', function (Blueprint $table) {
            $table->dropForeign(['beneficiary_bcc_id']);
            $table->dropIndex(['tenant_id', 'beneficiary_bcc_id']);
            $table->dropColumn(['beneficiary_bcc_id', 'beneficiary_bcc_name', 'beneficiary_place']);
        });
    }
};
