<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('family_members', 'tenant_id')) {
            Schema::table('family_members', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            });
        }

        DB::table('family_members')
            ->whereNull('tenant_id')
            ->orderBy('id')
            ->chunkById(500, function ($members): void {
                foreach ($members as $member) {
                    $tenantId = DB::table('families')
                        ->where('id', $member->family_id)
                        ->value('tenant_id');

                    if ($tenantId === null) {
                        continue;
                    }

                    DB::table('family_members')
                        ->where('id', $member->id)
                        ->update(['tenant_id' => $tenantId]);
                }
            }, 'id');

        Schema::table('family_members', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'family_id']);
        });
    }

    public function down(): void
    {
        Schema::table('family_members', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id', 'family_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
