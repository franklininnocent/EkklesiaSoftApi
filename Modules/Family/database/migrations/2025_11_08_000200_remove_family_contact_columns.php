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
        Schema::table('families', function (Blueprint $table) {
            $columnsToDrop = [];

            foreach (['primary_phone', 'secondary_phone', 'email'] as $column) {
                if (Schema::hasColumn('families', $column)) {
                    $columnsToDrop[] = $column;
                }
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            if (!Schema::hasColumn('families', 'primary_phone')) {
                $table->string('primary_phone', 20)->nullable();
            }

            if (!Schema::hasColumn('families', 'secondary_phone')) {
                $table->string('secondary_phone', 20)->nullable();
            }

            if (!Schema::hasColumn('families', 'email')) {
                $table->string('email', 255)->nullable();
            }
        });
    }
};


