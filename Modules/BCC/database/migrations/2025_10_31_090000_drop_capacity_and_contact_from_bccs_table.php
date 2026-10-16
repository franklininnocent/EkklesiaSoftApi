<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bccs', function (Blueprint $table) {
            if (Schema::hasColumn('bccs', 'min_families')) {
                $table->dropColumn('min_families');
            }
            if (Schema::hasColumn('bccs', 'max_families')) {
                $table->dropColumn('max_families');
            }
            if (Schema::hasColumn('bccs', 'contact_phone')) {
                $table->dropColumn('contact_phone');
            }
            if (Schema::hasColumn('bccs', 'contact_email')) {
                $table->dropColumn('contact_email');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bccs', function (Blueprint $table) {
            if (!Schema::hasColumn('bccs', 'min_families')) {
                $table->integer('min_families')->default(10)->comment('Minimum number of families');
            }
            if (!Schema::hasColumn('bccs', 'max_families')) {
                $table->integer('max_families')->default(50)->comment('Maximum number of families');
            }
            if (!Schema::hasColumn('bccs', 'contact_phone')) {
                $table->string('contact_phone', 20)->nullable();
            }
            if (!Schema::hasColumn('bccs', 'contact_email')) {
                $table->string('contact_email', 255)->nullable();
            }
        });
    }
};
