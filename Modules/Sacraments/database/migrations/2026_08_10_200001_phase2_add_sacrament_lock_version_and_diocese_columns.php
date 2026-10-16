<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Additive columns on sacraments (lock_version, registry, marriage diocese denorm).
 * No behavior change; dual-write targets for Phase 3+.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sacraments', function (Blueprint $table) {
            if (! Schema::hasColumn('sacraments', 'lock_version')) {
                $table->unsignedInteger('lock_version')->default(0)->after('status');
            }
            if (! Schema::hasColumn('sacraments', 'registry_entry')) {
                $table->string('registry_entry', 100)->nullable()->after('page_number');
            }

            if (! Schema::hasColumn('sacraments', 'marriage_bride_diocese_name')) {
                $table->string('marriage_bride_diocese_name')->nullable()->after('marriage_bride_church_address');
                $table->string('marriage_bride_diocese_region')->nullable()->after('marriage_bride_diocese_name');
                $table->string('marriage_bride_diocese_country')->nullable()->after('marriage_bride_diocese_region');
            }

            if (! Schema::hasColumn('sacraments', 'marriage_groom_diocese_name')) {
                $table->string('marriage_groom_diocese_name')->nullable()->after('marriage_groom_church_address');
                $table->string('marriage_groom_diocese_region')->nullable()->after('marriage_groom_diocese_name');
                $table->string('marriage_groom_diocese_country')->nullable()->after('marriage_groom_diocese_region');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sacraments', function (Blueprint $table) {
            $columns = [
                'lock_version',
                'registry_entry',
                'marriage_bride_diocese_name',
                'marriage_bride_diocese_region',
                'marriage_bride_diocese_country',
                'marriage_groom_diocese_name',
                'marriage_groom_diocese_region',
                'marriage_groom_diocese_country',
            ];
            $existing = array_values(array_filter($columns, fn (string $c) => Schema::hasColumn('sacraments', $c)));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
