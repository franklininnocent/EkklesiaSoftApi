<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Support\LeadershipRoleNameNormalizer;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leadership_roles', function (Blueprint $table) {
            $table->string('normalized_title', 150)->nullable()->after('title');
            $table->unsignedBigInteger('created_by')->nullable()->after('is_active');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        $this->backfillNormalizedTitles();
        $this->deduplicateRoles();

        Schema::table('leadership_roles', function (Blueprint $table) {
            $table->string('normalized_title', 150)->nullable(false)->change();
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('
                CREATE UNIQUE INDEX leadership_roles_system_normalized_title_unique
                ON leadership_roles (normalized_title)
                WHERE tenant_id IS NULL
            ');
            DB::statement('
                CREATE UNIQUE INDEX leadership_roles_tenant_normalized_title_unique
                ON leadership_roles (tenant_id, normalized_title)
                WHERE tenant_id IS NOT NULL
            ');
            $this->createPostgresSystemCollisionTrigger();
        } elseif ($driver === 'sqlite') {
            DB::statement('
                CREATE UNIQUE INDEX leadership_roles_system_normalized_title_unique
                ON leadership_roles (normalized_title)
                WHERE tenant_id IS NULL
            ');
            DB::statement('
                CREATE UNIQUE INDEX leadership_roles_tenant_normalized_title_unique
                ON leadership_roles (tenant_id, normalized_title)
                WHERE tenant_id IS NOT NULL
            ');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS leadership_roles_prevent_tenant_system_title_collision ON leadership_roles');
            DB::statement('DROP FUNCTION IF EXISTS leadership_roles_prevent_tenant_system_title_collision()');
        }

        DB::statement('DROP INDEX IF EXISTS leadership_roles_system_normalized_title_unique');
        DB::statement('DROP INDEX IF EXISTS leadership_roles_tenant_normalized_title_unique');

        Schema::table('leadership_roles', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['normalized_title', 'created_by', 'updated_by']);
        });
    }

    private function backfillNormalizedTitles(): void
    {
        LeadershipRole::query()->orderBy('id')->each(function (LeadershipRole $role): void {
            $role->forceFill([
                'normalized_title' => LeadershipRoleNameNormalizer::normalize((string) $role->title),
            ])->saveQuietly();
        });
    }

    private function deduplicateRoles(): void
    {
        $groups = LeadershipRole::query()
            ->orderBy('id')
            ->get()
            ->groupBy(fn (LeadershipRole $role) => ($role->tenant_id ?? 'system').'|'.($role->normalized_title ?? ''));

        foreach ($groups as $key => $roles) {
            if ($roles->count() <= 1 || str_ends_with((string) $key, '|')) {
                continue;
            }

            $keeper = $this->pickKeeperRole($roles);
            $duplicates = $roles->filter(fn (LeadershipRole $role) => $role->id !== $keeper->id);

            foreach ($duplicates as $duplicate) {
                DB::table('leadership_assignments')
                    ->where('role_id', $duplicate->id)
                    ->update(['role_id' => $keeper->id]);

                $duplicate->delete();
            }
        }

        $systemRoles = LeadershipRole::query()->whereNull('tenant_id')->get()->keyBy('normalized_title');

        LeadershipRole::query()
            ->whereNotNull('tenant_id')
            ->orderBy('id')
            ->each(function (LeadershipRole $tenantRole) use ($systemRoles): void {
                $systemMatch = $systemRoles->get((string) $tenantRole->normalized_title);
                if ($systemMatch === null) {
                    return;
                }

                DB::table('leadership_assignments')
                    ->where('role_id', $tenantRole->id)
                    ->update(['role_id' => $systemMatch->id]);

                $tenantRole->delete();
            });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LeadershipRole>  $roles
     */
    private function pickKeeperRole($roles): LeadershipRole
    {
        return $roles->sortBy(function (LeadershipRole $role) {
            $assignmentCount = DB::table('leadership_assignments')
                ->where('role_id', $role->id)
                ->count();

            return [-$assignmentCount, $role->created_at?->timestamp ?? 0, $role->id];
        })->first();
    }

    private function createPostgresSystemCollisionTrigger(): void
    {
        DB::unprepared('
            CREATE OR REPLACE FUNCTION leadership_roles_prevent_tenant_system_title_collision()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.tenant_id IS NOT NULL AND EXISTS (
                    SELECT 1 FROM leadership_roles
                    WHERE tenant_id IS NULL
                      AND normalized_title = NEW.normalized_title
                ) THEN
                    RAISE unique_violation USING
                        MESSAGE = \'duplicate key value violates unique constraint "leadership_roles_system_normalized_title_unique"\',
                        DETAIL = \'Tenant role title matches a system role.\';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER leadership_roles_prevent_tenant_system_title_collision
            BEFORE INSERT OR UPDATE ON leadership_roles
            FOR EACH ROW
            EXECUTE PROCEDURE leadership_roles_prevent_tenant_system_title_collision();
        ');
    }
};
