<?php

namespace Modules\EcclesiasticalData\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Authentication\Models\Role;
use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Services\BishopService;
use Modules\EcclesiasticalData\Services\SuccessionService;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\Tenant;

/**
 * Deterministic bishop workflow E2E fixtures for a single tenant parish.
 *
 * Run:
 * BISHOP_E2E_TENANT_ID=<tenant-id> php artisan db:seed --class=Modules\\EcclesiasticalData\\Database\\Seeders\\BishopE2eSeeder
 */
class BishopE2eSeeder extends Seeder
{
    private const MARKER_CODE = 'E2E-BISHOP';

    private const BISHOP_NAME = 'E2E Bishop Current';

    public function run(): void
    {
        $tenantId = env('BISHOP_E2E_TENANT_ID');
        if (! $tenantId) {
            $this->command?->error('BISHOP_E2E_TENANT_ID is required.');

            return;
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            $this->command?->error("Tenant not found: {$tenantId}");

            return;
        }

        $this->ensureTenantBishopPermissions((int) $tenant->id);

        $diocese = DioceseManagement::query()->where('code', self::MARKER_CODE)->first();
        if (! $diocese) {
            $diocese = DioceseManagement::factory()->create([
                'name' => 'E2E Bishop Diocese',
                'code' => self::MARKER_CODE,
            ]);
        }

        ChurchProfile::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            ['archdiocese_id' => $diocese->id]
        );

        $hasOrdinary = BishopAppointment::query()
            ->where('diocese_id', $diocese->id)
            ->where('is_current', true)
            ->ordinary()
            ->exists();

        if (! $hasOrdinary) {
            $bishop = app(BishopService::class)->createPerson([
                'full_name' => self::BISHOP_NAME,
                'archdiocese_id' => $diocese->id,
                'status' => 'active',
            ], null, false);

            app(SuccessionService::class)->replaceCurrentOrdinary(
                (int) $diocese->id,
                $bishop,
                ['effective_date' => '2018-01-01'],
            );
        }

        $this->command?->info('Bishop E2E fixtures ready for tenant '.$tenantId.' (diocese '.$diocese->id.')');
    }

    private function ensureTenantBishopPermissions(int $tenantId): void
    {
        $permissionIds = Permission::query()
            ->whereIn('name', [
                'bishops.view',
                'bishops.submit_update_request',
                'bishops.view_own_requests',
            ])
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            $this->call(EcclesiasticalPermissionSeeder::class);
            $permissionIds = Permission::query()
                ->whereIn('name', [
                    'bishops.view',
                    'bishops.submit_update_request',
                    'bishops.view_own_requests',
                ])
                ->pluck('id');
        }

        Role::query()
            ->where('tenant_id', $tenantId)
            ->where('name', Role::TENANT_ADMINISTRATOR)
            ->each(function (Role $role) use ($permissionIds): void {
                $role->permissions()->syncWithoutDetaching($permissionIds);
                $role->clearUsersPermissionCache();
            });
    }
}
