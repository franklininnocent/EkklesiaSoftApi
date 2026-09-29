<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — Eucharist subtype, Holy Orders typed attrs, Anointing place classification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sacraments', function (Blueprint $table) {
            $table->string('event_subtype', 64)->nullable()->after('sacrament_type_id');
            $table->jsonb('typed_attributes')->nullable()->after('notes');
            $table->string('place_classification', 32)->nullable()->after('place_administered');

            $table->index(
                ['tenant_id', 'sacrament_type_id', 'event_subtype'],
                'sacraments_tenant_type_subtype_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('sacraments', function (Blueprint $table) {
            $table->dropIndex('sacraments_tenant_type_subtype_idx');
            $table->dropColumn(['event_subtype', 'typed_attributes', 'place_classification']);
        });
    }
};
