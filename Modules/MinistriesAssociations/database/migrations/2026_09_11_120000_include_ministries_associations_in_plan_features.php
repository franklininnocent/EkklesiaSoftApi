<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FEATURE = 'ministries_associations';

    public function up(): void
    {
        $this->appendFeature('tenants');
        $this->appendFeature('subscription_plans');
    }

    public function down(): void
    {
        $this->removeFeature('tenants');
        $this->removeFeature('subscription_plans');
    }

    private function appendFeature(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'features')) {
            return;
        }

        $rows = DB::table($table)->select('id', 'features')->get();

        foreach ($rows as $row) {
            $features = $this->decodeFeatures($row->features);
            if (in_array(self::FEATURE, $features, true)) {
                continue;
            }

            $features[] = self::FEATURE;
            sort($features);

            DB::table($table)
                ->where('id', $row->id)
                ->update([
                    'features' => json_encode(array_values(array_unique($features))),
                    'updated_at' => now(),
                ]);
        }
    }

    private function removeFeature(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'features')) {
            return;
        }

        $rows = DB::table($table)->select('id', 'features')->get();

        foreach ($rows as $row) {
            $features = $this->decodeFeatures($row->features);
            if ($features === []) {
                continue;
            }

            $filtered = array_values(array_filter(
                $features,
                static fn (string $feature): bool => $feature !== self::FEATURE
            ));

            DB::table($table)
                ->where('id', $row->id)
                ->update([
                    'features' => json_encode($filtered),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @return list<string>
     */
    private function decodeFeatures(mixed $features): array
    {
        if ($features instanceof \stdClass) {
            $features = json_decode(json_encode($features), true);
        }

        if (is_string($features)) {
            $decoded = json_decode($features, true);
            $features = is_string($decoded) ? json_decode($decoded, true) : $decoded;
        }

        if (! is_array($features)) {
            return [];
        }

        return array_values(array_filter(
            $features,
            static fn (mixed $feature): bool => is_string($feature) && $feature !== ''
        ));
    }
};
