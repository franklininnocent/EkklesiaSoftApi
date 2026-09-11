<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_request_types', function (Blueprint $table): void {
            $table->boolean('requires_bug_fields')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('support_request_types', function (Blueprint $table): void {
            $table->dropColumn('requires_bug_fields');
        });
    }
};
