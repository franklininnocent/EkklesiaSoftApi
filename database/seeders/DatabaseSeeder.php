<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Authoritative EkklesiaSoft database initialization pipeline.
     */
    public function run(): void
    {
        $this->command?->info('EkklesiaSoft — complete database seeding pipeline');
        $this->command?->line('');

        // 1. System roles + super admin account (must precede permission role assignments)
        $this->callIfExists([
            \Modules\Authentication\Database\Seeders\AuthenticationDatabaseSeeder::class,
        ]);

        // 2. Permissions / RBAC catalog (includes many module permission seeders)
        $this->callIfExists([
            \Modules\RolesAndPermissions\Database\Seeders\RolesAndPermissionsDatabaseSeeder::class,
        ]);

        // 3. Geographic / ecclesiastical master data
        $this->callIfExists([
            \Modules\Tenants\Database\Seeders\TenantsDatabaseSeeder::class,
        ]);

        // 4. Global leadership role catalog
        $this->callIfExists([
            \Modules\Tenants\Database\Seeders\LeadershipRolesSeeder::class,
        ]);

        // 5. Ecclesiastical reference data (bishops, offices)
        $this->callIfExists([
            \Modules\EcclesiasticalData\Database\Seeders\EcclesiasticalDataDatabaseSeeder::class,
        ]);

        // 6. Module permission catalogs not included in RolesAndPermissionsDatabaseSeeder
        $this->callIfExists([
            \Modules\Donations\Database\Seeders\DonationsDatabaseSeeder::class,
            \Modules\Sacraments\Database\Seeders\SacramentPermissionsSeeder::class,
            \Modules\Family\Database\Seeders\FamilyTransitionPermissionSeeder::class,
            \Modules\MinistriesAssociations\Database\Seeders\MinistriesAssociationsDatabaseSeeder::class,
        ]);

        // 7. Support ticket lookup tables (permissions already seeded in step 1)
        $this->callIfExists([
            \Modules\SupportTickets\Database\Seeders\SupportTicketsLookupSeeder::class,
        ]);

        // 8. Sacrament types (reference catalog)
        $this->callIfExists([
            \Modules\Sacraments\Database\Seeders\SacramentsDatabaseSeeder::class,
        ]);

        // 9. Per-tenant module defaults (donation categories, ministries taxonomy)
        $this->callIfExists([
            TenantModuleDefaultsSeeder::class,
        ]);

        // 10. Deterministic demo users
        $this->callIfExists([
            \Modules\Authentication\Database\Seeders\UsersSeeder::class,
        ]);

        // 11. OAuth password grant client required for login tokens
        $this->callIfExists([
            OAuthClientSeeder::class,
        ]);

        $this->command?->line('');
        $this->command?->info('EkklesiaSoft database seeding complete.');
    }

    private function callIfExists(array $seeders): void
    {
        foreach ($seeders as $seeder) {
            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
