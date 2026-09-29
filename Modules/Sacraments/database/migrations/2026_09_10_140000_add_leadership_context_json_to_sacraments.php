<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sacraments', function (Blueprint $table) {
            if (! Schema::hasColumn('sacraments', 'leadership_context_json')) {
                $table->json('leadership_context_json')->nullable()->after('typed_attributes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sacraments', function (Blueprint $table) {
            if (Schema::hasColumn('sacraments', 'leadership_context_json')) {
                $table->dropColumn('leadership_context_json');
            }
        });
    }
};
