<?php

namespace Modules\Family\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * @deprecated Parish zones were removed from the schema (see 2025_10_30_000002_drop_parish_zones_table).
 */
class ParishZonesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! Schema::hasTable('parish_zones')) {
            $this->command?->warn('Skipping parish zones seeding: parish_zones table no longer exists.');

            return;
        }

        $this->command?->error('parish_zones table exists but seeding is no longer supported.');
    }
}
