<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacrament_participants')) {
            return;
        }

        Schema::table('sacrament_participants', function (Blueprint $table) {
            if (! Schema::hasColumn('sacrament_participants', 'leadership_assignment_id')) {
                $table->uuid('leadership_assignment_id')->nullable()->after('church_leadership_id');
                $table->foreign('leadership_assignment_id')
                    ->references('id')
                    ->on('leadership_assignments')
                    ->nullOnDelete();
                $table->index('leadership_assignment_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sacrament_participants')) {
            return;
        }

        Schema::table('sacrament_participants', function (Blueprint $table) {
            if (Schema::hasColumn('sacrament_participants', 'leadership_assignment_id')) {
                $table->dropForeign(['leadership_assignment_id']);
                $table->dropColumn('leadership_assignment_id');
            }
        });
    }
};
