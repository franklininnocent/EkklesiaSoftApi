<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * @deprecated Use {@see TenantMinistriesDemoSeeder} — kept for backward-compatible class references.
 */
class TenantMinistriesMembershipDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(TenantMinistriesDemoSeeder::class);
    }
}
