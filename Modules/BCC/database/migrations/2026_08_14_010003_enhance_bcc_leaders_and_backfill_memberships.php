<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bcc_leaders', function (Blueprint $table) {
            if (! Schema::hasColumn('bcc_leaders', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            }
        });

        $leaders = DB::table('bcc_leaders')->whereNull('tenant_id')->get(['id', 'bcc_id']);
        foreach ($leaders as $leader) {
            $tenantId = DB::table('bccs')->where('id', $leader->bcc_id)->value('tenant_id');
            if ($tenantId !== null) {
                DB::table('bcc_leaders')->where('id', $leader->id)->update(['tenant_id' => $tenantId]);
            }
        }

        $this->dropLegacyLeaderUnique();

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS bcc_leaders_uq_active_member_role
                ON bcc_leaders (bcc_id, family_member_id, role)
                WHERE is_active = true
                  AND deleted_at IS NULL
            ");

            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS bcc_leaders_uq_active_primary
                ON bcc_leaders (bcc_id)
                WHERE role = 'leader'
                  AND is_active = true
                  AND deleted_at IS NULL
            ");
        }

        Schema::table('bcc_leaders', function (Blueprint $table) {
            $table->index(['tenant_id', 'bcc_id', 'is_active'], 'bcc_leaders_tenant_bcc_active_idx');
        });

        $families = DB::table('families')
            ->whereNotNull('bcc_id')
            ->whereNull('deleted_at')
            ->get(['id', 'tenant_id', 'bcc_id', 'created_at']);

        foreach ($families as $family) {
            $exists = DB::table('bcc_family_memberships')
                ->where('family_id', $family->id)
                ->where('is_current', true)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                continue;
            }

            $joinedDate = $family->created_at
                ? substr((string) $family->created_at, 0, 10)
                : now()->toDateString();

            DB::table('bcc_family_memberships')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $family->tenant_id,
                'bcc_id' => $family->bcc_id,
                'family_id' => $family->id,
                'status' => 'active',
                'joined_date' => $joinedDate,
                'is_current' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS bcc_leaders_uq_active_member_role');
            DB::statement('DROP INDEX IF EXISTS bcc_leaders_uq_active_primary');
        }

        Schema::table('bcc_leaders', function (Blueprint $table) {
            $table->dropIndex('bcc_leaders_tenant_bcc_active_idx');
            if (Schema::hasColumn('bcc_leaders', 'tenant_id')) {
                $table->dropColumn('tenant_id');
            }
        });

        Schema::table('bcc_leaders', function (Blueprint $table) {
            $table->unique(['bcc_id', 'family_member_id', 'role'], 'unique_active_bcc_leader');
        });
    }

    private function dropLegacyLeaderUnique(): void
    {
        try {
            Schema::table('bcc_leaders', function (Blueprint $table) {
                $table->dropUnique('unique_active_bcc_leader');
            });
        } catch (\Throwable) {
            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                DB::statement('DROP INDEX IF EXISTS unique_active_bcc_leader');
            }
        }
    }
};
