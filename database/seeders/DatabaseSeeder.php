<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Authentication\Database\Seeders\AuthenticationDatabaseSeeder;
use Modules\Authentication\Database\Seeders\UsersSeeder;
use Modules\Donations\Database\Seeders\DonationsDatabaseSeeder;
use Modules\EcclesiasticalData\Database\Seeders\EcclesiasticalDataDatabaseSeeder;
use Modules\Family\Database\Seeders\FamilyTransitionPermissionSeeder;
use Modules\MinistriesAssociations\Database\Seeders\MinistriesAssociationsDatabaseSeeder;
use Modules\RolesAndPermissions\Database\Seeders\RolesAndPermissionsDatabaseSeeder;
use Modules\Sacraments\Database\Seeders\SacramentPermissionsSeeder;
use Modules\Sacraments\Database\Seeders\SacramentsDatabaseSeeder;
use Modules\Subscriptions\Database\Seeders\SubscriptionsDatabaseSeeder;
use Modules\SupportTickets\Database\Seeders\SupportTicketsLookupSeeder;
use Modules\Tenants\Database\Seeders\LeadershipRolesSeeder;
use Modules\Tenants\Database\Seeders\TenantsDatabaseSeeder;

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
            AuthenticationDatabaseSeeder::class,
        ]);

        // 2. Permissions / RBAC catalog (includes many module permission seeders)
        $this->callIfExists([
            RolesAndPermissionsDatabaseSeeder::class,
        ]);

        // 3. Geographic / ecclesiastical master data
        $this->callIfExists([
            TenantsDatabaseSeeder::class,
        ]);

        // 3b. Subscription catalog (features, plans, policies) + tenant plan backfill
        $this->callIfExists([
            SubscriptionsDatabaseSeeder::class,
        ]);

        // 4. Global leadership role catalog
        $this->callIfExists([
            LeadershipRolesSeeder::class,
        ]);

        // 5. Ecclesiastical reference data (bishops, offices)
        $this->callIfExists([
            EcclesiasticalDataDatabaseSeeder::class,
        ]);

        // 6. Module permission catalogs not included in RolesAndPermissionsDatabaseSeeder
        $this->callIfExists([
            DonationsDatabaseSeeder::class,
            SacramentPermissionsSeeder::class,
            FamilyTransitionPermissionSeeder::class,
            MinistriesAssociationsDatabaseSeeder::class,
        ]);

        // 7. Support ticket lookup tables (permissions already seeded in step 1)
        $this->callIfExists([
            SupportTicketsLookupSeeder::class,
        ]);

        // 8. Sacrament types (reference catalog)
        $this->callIfExists([
            SacramentsDatabaseSeeder::class,
        ]);

        // 9. Per-tenant module defaults (donation categories, ministries taxonomy)
        $this->callIfExists([
            TenantModuleDefaultsSeeder::class,
        ]);

        // 10. Deterministic demo users
        $this->callIfExists([
            UsersSeeder::class,
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
