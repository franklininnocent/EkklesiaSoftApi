<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mass_intention_assignments', function (Blueprint $table) {
            $table->text('date_variance_reason')->nullable()->after('assigned_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('mass_intention_assignments', function (Blueprint $table) {
            $table->dropColumn('date_variance_reason');
        });
    }
};
