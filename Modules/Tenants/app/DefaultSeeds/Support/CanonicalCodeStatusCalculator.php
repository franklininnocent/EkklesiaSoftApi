<?php

namespace Modules\Tenants\DefaultSeeds\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Tenants\DefaultSeeds\DefaultSeedStatus;

/**
 * Computes catalog status from tenant rows matched by stable business codes.
 */
final class CanonicalCodeStatusCalculator
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<array{code: string, name: string}>  $canonicalDefaults
     * @return array{
     *     expected_count: int,
     *     matched_count: int,
     *     missing_count: int,
     *     has_other_records: bool,
     *     missing_names: list<string>,
     *     status: string
     * }
     */
    public static function forModel(
        string $modelClass,
        int $tenantId,
        array $canonicalDefaults,
        bool $withTrashed = true,
    ): array {
        $canonicalCodes = array_column($canonicalDefaults, 'code');
        $nameByCode = [];
        foreach ($canonicalDefaults as $row) {
            $nameByCode[$row['code']] = $row['name'];
        }

        $expectedCount = count($canonicalCodes);
        $query = $modelClass::query()->where('tenant_id', $tenantId);
        if ($withTrashed && method_exists($modelClass, 'withTrashed')) {
            $query = $modelClass::withTrashed()->where('tenant_id', $tenantId);
        }

        $existingCodes = $query->pluck('code')->all();
        $matchedCodes = array_values(array_intersect($canonicalCodes, $existingCodes));
        $matchedCount = count($matchedCodes);
        $missingCodes = array_values(array_diff($canonicalCodes, $matchedCodes));
        $missingNames = array_map(fn (string $code) => $nameByCode[$code] ?? $code, $missingCodes);
        $otherCount = count(array_diff($existingCodes, $canonicalCodes));

        return [
            'expected_count' => $expectedCount,
            'matched_count' => $matchedCount,
            'missing_count' => count($missingCodes),
            'has_other_records' => $otherCount > 0,
            'missing_names' => $missingNames,
            'status' => DefaultSeedStatus::deriveStatus($expectedCount, $matchedCount),
        ];
    }
}
