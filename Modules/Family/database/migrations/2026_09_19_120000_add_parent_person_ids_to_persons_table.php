<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->uuid('father_person_id')->nullable()->after('mother_name');
            $table->uuid('mother_person_id')->nullable()->after('father_person_id');

            $table->foreign('father_person_id')
                ->references('id')
                ->on('persons')
                ->nullOnDelete();

            $table->foreign('mother_person_id')
                ->references('id')
                ->on('persons')
                ->nullOnDelete();

            $table->index('father_person_id', 'persons_father_person_id_idx');
            $table->index('mother_person_id', 'persons_mother_person_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->dropForeign(['father_person_id']);
            $table->dropForeign(['mother_person_id']);
            $table->dropIndex('persons_father_person_id_idx');
            $table->dropIndex('persons_mother_person_id_idx');
            $table->dropColumn(['father_person_id', 'mother_person_id']);
        });
    }
};
