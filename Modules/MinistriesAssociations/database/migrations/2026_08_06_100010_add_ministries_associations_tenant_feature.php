<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('tenants')) {
            return;
        }

        $tenants = DB::table('tenants')->select('id', 'features')->get();

        foreach ($tenants as $tenant) {
            $features = $tenant->features;

            if (is_string($features)) {
                $features = json_decode($features, true);
            }

            if (!is_array($features)) {
                $features = [];
            }

            if (in_array('ministries_associations', $features, true)) {
                continue;
            }

            $features[] = 'ministries_associations';
            sort($features);

            DB::table('tenants')
                ->where('id', $tenant->id)
                ->update([
                    'features' => json_encode(array_values(array_unique($features))),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('tenants')) {
            return;
        }

        $tenants = DB::table('tenants')->select('id', 'features')->get();

        foreach ($tenants as $tenant) {
            $features = $tenant->features;

            if (is_string($features)) {
                $features = json_decode($features, true);
            }

            if (!is_array($features)) {
                continue;
            }

            $filtered = array_values(array_filter(
                $features,
                static fn (string $feature): bool => $feature !== 'ministries_associations'
            ));

            DB::table('tenants')
                ->where('id', $tenant->id)
                ->update([
                    'features' => json_encode($filtered),
                    'updated_at' => now(),
                ]);
        }
    }
};
