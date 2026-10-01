<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $sm = Schema::getConnection()->getSchemaBuilder();
            $indexes = $sm->getIndexes('families');
            $names = array_map(fn ($index) => $index['name'] ?? '', $indexes);

            if (! in_array('families_tenant_status_created_idx', $names, true)) {
                $table->index(['tenant_id', 'status', 'created_at'], 'families_tenant_status_created_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $indexes = Schema::getConnection()->getSchemaBuilder()->getIndexes('families');
            $names = array_map(fn ($index) => $index['name'] ?? '', $indexes);
            if (in_array('families_tenant_status_created_idx', $names, true)) {
                $table->dropIndex('families_tenant_status_created_idx');
            }
        });
    }
};
