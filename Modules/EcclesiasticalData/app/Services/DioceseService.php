<?php

namespace Modules\EcclesiasticalData\Services;

use Modules\EcclesiasticalData\Repositories\DioceseRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DioceseService
{
    protected DioceseRepository $repository;

    public function __construct(DioceseRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Get paginated dioceses with filters
     */
    public function getPaginated(array $params)
    {
        $cacheKey = 'dioceses.paginated.' . md5(json_encode($params));
        
        return Cache::remember($cacheKey, 300, function () use ($params) {
            return $this->repository->getDiocesesPaginated($params);
        });
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
     */
    protected function clearCache(?string $dioceseId = null): void
    {
        Cache::forget('dioceses.statistics');
        Cache::forget('archdioceses.list');
        
        if ($dioceseId) {
            Cache::forget("diocese.{$dioceseId}.full");
        }
        
        // Clear paginated cache (simplified - in production use tags)
        Cache::flush(); // Use cache tags in production
    }
}

