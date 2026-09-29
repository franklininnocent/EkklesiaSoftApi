<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->string('fiscal_year_start_month', 2)->default('01')->after('currency_symbol');
            $table->string('fiscal_year_start_day', 2)->default('01')->after('fiscal_year_start_month');
        });

        Schema::table('donation_settings', function (Blueprint $table) {
            $table->string('financial_year_source', 16)->default('country')->after('financial_year_start_day');
        });
    }

    public function down(): void
    {
        Schema::table('donation_settings', function (Blueprint $table) {
            $table->dropColumn('financial_year_source');
        });

        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn(['fiscal_year_start_month', 'fiscal_year_start_day']);
        });
    }
};
