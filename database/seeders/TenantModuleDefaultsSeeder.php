<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Donations\Database\Seeders\DonationCategorySeeder;
use Modules\MinistriesAssociations\Database\Seeders\MinistriesAssociationsDefaultSeeder;
use Modules\Tenants\Models\Tenant;

class TenantModuleDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::query()->orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->command?->warn('Skipping tenant module defaults: no tenants found.');

            return;
        }

        foreach ($tenants as $tenant) {
            $this->command?->info("Seeding module defaults for tenant #{$tenant->id} ({$tenant->name})");

            if (class_exists(DonationCategorySeeder::class)) {
                (new DonationCategorySeeder)->run($tenant->id);
            }

            if (class_exists(MinistriesAssociationsDefaultSeeder::class)) {
                (new MinistriesAssociationsDefaultSeeder)->run($tenant->id);
            }
        }
    }
}
