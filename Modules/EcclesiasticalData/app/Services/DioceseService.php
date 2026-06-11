<?php

namespace Modules\EcclesiasticalData\Services;

use Modules\EcclesiasticalData\Repositories\DioceseRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DioceseService
{
    protected const PAGINATION_CACHE_KEY_SET = 'dioceses.paginated.keys';
    protected DioceseRepository $repository;

    public function __construct(DioceseRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Get paginated dioceses with filters using traditional Laravel pagination
     * Cache TTL reduced to 2 minutes for list queries to ensure fresh data
     */
    public function getPaginated(array $params)
    {
        // Use traditional pagination (simpler and more maintainable)
        $cacheKey = 'dioceses.paginated.' . md5(json_encode($params));
        
        $paginator = Cache::remember($cacheKey, 120, function () use ($params) {
            return $this->repository->getDiocesesPaginatedLegacy($params);
        });

        $this->rememberPaginationKey($cacheKey);

        return $paginator;
    }

    /**
     * Get diocese by ID with relationships
     */
    public function getById(string $id)
    {
        $cacheKey = "diocese.{$id}.full";
        
        return Cache::remember($cacheKey, 600, function () use ($id) {
            return $this->repository->findWithRelations($id);
        });
    }

    /**
     * Create new diocese
     */
    public function create(array $data)
    {
        DB::beginTransaction();
        
        try {
            // ID is auto-increment, don't set it manually
            unset($data['id']);
            
            $diocese = $this->repository->create($data);
            
            $this->clearCache();
            
            DB::commit();
            
            return $diocese;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update diocese
     */
    public function update(string $id, array $data)
    {
        DB::beginTransaction();
        
        try {
            $diocese = $this->repository->update($id, $data);
            
            // Load relationships after update
            $diocese->load([
                'country:id,name,iso2',
                'state:id,name,state_code',
                'denomination:id,name'
            ]);
            
            $this->clearCache($id);
            
            DB::commit();
            
            return $diocese;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Delete diocese
     */
    public function delete(string $id): bool
    {
        DB::beginTransaction();
        
        try {
            $result = $this->repository->delete($id);
            
            $this->clearCache($id);
            
            DB::commit();
            
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get dioceses by country
     */
    public function getByCountry(int $countryId)
    {
        $cacheKey = "dioceses.country.{$countryId}";
        
        return Cache::remember($cacheKey, 600, function () use ($countryId) {
            return $this->repository->getByCountry($countryId);
        });
    }

    /**
     * Get archdioceses only
     */
    public function getArchdioceses()
    {
        return Cache::remember('archdioceses.list', 600, function () {
            return $this->repository->getArchdioceses();
        });
    }

    /**
     * Get statistics
     */
    public function getStatistics()
    {
        return Cache::remember('dioceses.statistics', 600, function () {
            return $this->repository->getStatistics();
        });
    }

    /**
     * Bulk import dioceses
     */
    public function bulkImport(array $dioceses)
    {
        DB::beginTransaction();
        
        try {
            $imported = 0;
            $errors = [];
            
            foreach ($dioceses as $index => $dioceseData) {
                try {
                    $this->repository->create($dioceseData);
                    $imported++;
                } catch (\Exception $e) {
                    $errors[] = [
                        'row' => $index + 1,
                        'data' => $dioceseData,
                        'error' => $e->getMessage(),
                    ];
                }
            }
            
            $this->clearCache();
            
            DB::commit();
            
            return [
                'imported' => $imported,
                'errors' => $errors,
                'total' => count($dioceses),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Clear diocese cache
     * Simplified cache clearing - removes all pagination caches
     */
    protected function clearCache(?string $dioceseId = null): void
    {
        // Clear statistics and archdioceses cache
        Cache::forget('dioceses.statistics');
        Cache::forget('archdioceses.list');
        
        if ($dioceseId) {
            Cache::forget("diocese.{$dioceseId}.full");
        }
        
        // Clear paginated cache patterns (works with Redis cache driver)
        try {
            $store = Cache::getStore();
            $this->forgetStoredPaginationKeys();
            if (method_exists($store, 'getRedis')) {
                $redis = $store->getRedis();
                $prefix = config('cache.prefix', 'laravel_cache');
                
                // Clear all paginated caches
                $keys = $redis->keys("{$prefix}:dioceses.paginated.*");
                if ($keys) {
                    foreach ($keys as $key) {
                        $cacheKey = str_replace("{$prefix}:", '', $key);
                        Cache::forget($cacheKey);
                    }
                }
                
                // Clear country-specific caches
                $countryKeys = $redis->keys("{$prefix}:dioceses.country.*");
                if ($countryKeys) {
                    foreach ($countryKeys as $key) {
                        $cacheKey = str_replace("{$prefix}:", '', $key);
                        Cache::forget($cacheKey);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->forgetStoredPaginationKeys();
            // If pattern matching fails (non-Redis cache), cache will expire naturally
            // This is acceptable as paginated caches have short TTL (2 minutes)
        }
    }

    /**
     * Track paginated cache keys so they can be flushed even when pattern deletion is unavailable
     */
    protected function rememberPaginationKey(string $cacheKey): void
    {
        $keys = Cache::get(self::PAGINATION_CACHE_KEY_SET, []);

        if (!in_array($cacheKey, $keys, true)) {
            $keys[] = $cacheKey;

            // Avoid unbounded growth
            if (count($keys) > 50) {
                $keys = array_slice($keys, -50);
            }

            Cache::forever(self::PAGINATION_CACHE_KEY_SET, $keys);
        }
    }

    /**
     * Remove all stored pagination cache keys
     */
    protected function forgetStoredPaginationKeys(): void
    {
        $keys = Cache::pull(self::PAGINATION_CACHE_KEY_SET, []);

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }
}

