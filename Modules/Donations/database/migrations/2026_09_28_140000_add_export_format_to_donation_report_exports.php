<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_report_exports', function (Blueprint $table): void {
            $table->string('export_format', 8)->default('csv')->after('report_type');
        });
    }

    public function down(): void
    {
        Schema::table('donation_report_exports', function (Blueprint $table): void {
            $table->dropColumn('export_format');
        });
    }
};
