<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_projects', function (Blueprint $table): void {
            $table->enum('entity_kind', ['project', 'campaign'])->default('project')->after('code');
            $table->enum('campaign_type', ['building', 'charity', 'event', 'general'])->nullable()->after('entity_kind');
            $table->index(['tenant_id', 'entity_kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('donation_projects', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'entity_kind', 'status']);
            $table->dropColumn(['entity_kind', 'campaign_type']);
        });
    }
};
