<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sacrament_certificates', function (Blueprint $table) {
            $table->string('verification_token', 64)->nullable()->unique()->after('supersedes_certificate_id');
            $table->timestamp('verification_revoked_at')->nullable()->after('verification_token');
            $table->string('html_storage_key')->nullable()->after('storage_key');
        });

        if (Schema::hasTable('denominations')) {
            $now = now();
            $exists = DB::table('denominations')->where('code', 'CSI')->exists();
            if (! $exists) {
                DB::table('denominations')->insert([
                    'name' => 'Church of South India',
                    'code' => 'CSI',
                    'description' => 'Church of South India, a united Protestant church in India',
                    'active' => 1,
                    'display_order' => 34,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('sacrament_certificates', function (Blueprint $table) {
            $table->dropUnique(['verification_token']);
            $table->dropColumn(['verification_token', 'verification_revoked_at', 'html_storage_key']);
        });
    }
};
