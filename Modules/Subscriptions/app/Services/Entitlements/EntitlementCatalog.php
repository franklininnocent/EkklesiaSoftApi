<?php

namespace Modules\Subscriptions\Services\Entitlements;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Support\EntitlementCacheVersion;
use Modules\Subscriptions\Support\SubscriptionCatalogDefinition;

/**
 * Cached, read-only snapshot of the feature catalog and dependency graph.
 */
class EntitlementCatalog
{
    /** @var array{features: array<string, array<string, mixed>>, legacy: array<string, string>, dependencies: array<string, list<string>>, ids: array<int, string>}|null */
    private ?array $snapshot = null;

    private ?int $snapshotVersion = null;

    /**
     * @return array{features: array<string, array<string, mixed>>, legacy: array<string, string>, dependencies: array<string, list<string>>, ids: array<int, string>}
     */
    public function snapshot(): array
    {
        $version = EntitlementCacheVersion::catalog();
        if ($this->snapshot !== null && $this->snapshotVersion === $version) {
            return $this->snapshot;
        }

        $ttl = (int) config('subscriptions.cache.ttl_seconds', 900);
        $this->snapshot = Cache::remember(EntitlementCacheVersion::catalogSnapshotKey(), $ttl, fn () => $this->load());
        $this->snapshotVersion = $version;

        return $this->snapshot;
    }

    public function flush(): void
    {
        $this->snapshot = null;
        $this->snapshotVersion = null;
        EntitlementCacheVersion::bumpCatalog();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function feature(string $code): ?array
    {
        return $this->snapshot()['features'][strtoupper($code)] ?? null;
    }

    public function codeForLegacyKey(string $legacyKey): ?string
    {
        return $this->snapshot()['legacy'][$legacyKey] ?? null;
    }

    public function codeForId(int $featureId): ?string
    {
        return $this->snapshot()['ids'][$featureId] ?? null;
    }

    /**
     * @return list<string>
     */
    public function dependenciesOf(string $code): array
    {
        return $this->snapshot()['dependencies'][strtoupper($code)] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function features(): array
    {
        return $this->snapshot()['features'];
    }

    /**
     * @return array{features: array<string, array<string, mixed>>, legacy: array<string, string>, dependencies: array<string, list<string>>, ids: array<int, string>}
     */
    private function load(): array
    {
        try {
            $rows = DB::table('features')
                ->select(['id', 'code', 'name', 'feature_type', 'unit', 'legacy_key', 'is_core', 'legacy_default', 'is_active', 'category'])
                ->get();
            $deps = DB::table('feature_dependencies as d')
                ->join('features as f', 'f.id', '=', 'd.feature_id')
                ->join('features as r', 'r.id', '=', 'd.depends_on_feature_id')
                ->select(['f.code as code', 'r.code as requires'])
                ->get();
        } catch (QueryException) {
            return $this->fromDefinition();
        }

        if ($rows->isEmpty()) {
            return $this->fromDefinition();
        }

        $features = [];
        $legacy = [];
        $ids = [];
        foreach ($rows as $row) {
            $features[$row->code] = [
                'id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->feature_type,
                'unit' => $row->unit,
                'legacy_key' => $row->legacy_key,
                'is_core' => (bool) $row->is_core,
                'legacy_default' => (bool) $row->legacy_default,
                'is_active' => (bool) $row->is_active,
                'category' => $row->category,
            ];
            $ids[(int) $row->id] = $row->code;
            if ($row->legacy_key) {
                $legacy[$row->legacy_key] = $row->code;
            }
        }

        $dependencies = [];
        foreach ($deps as $dep) {
            $dependencies[$dep->code][] = $dep->requires;
        }

        return compact('features', 'legacy', 'dependencies', 'ids');
    }

    /**
     * Pre-migration fallback so legacy behaviour is preserved before the catalog is seeded.
     */
    private function fromDefinition(): array
    {
        $features = [];
        $legacy = [];
        foreach (SubscriptionCatalogDefinition::features() as $def) {
            $features[$def['code']] = [
                'id' => null,
                'code' => $def['code'],
                'name' => $def['name'],
                'type' => $def['feature_type'],
                'unit' => $def['unit'],
                'legacy_key' => $def['legacy_key'],
                'is_core' => (bool) $def['is_core'],
                'legacy_default' => (bool) $def['legacy_default'],
                'is_active' => true,
                'category' => $def['category'],
            ];
            if ($def['legacy_key']) {
                $legacy[$def['legacy_key']] = $def['code'];
            }
        }

        return [
            'features' => $features,
            'legacy' => $legacy,
            'dependencies' => SubscriptionCatalogDefinition::dependencies(),
            'ids' => [],
        ];
    }
}
