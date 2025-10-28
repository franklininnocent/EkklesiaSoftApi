<?php

namespace Modules\EcclesiasticalData\Services;

use Modules\EcclesiasticalData\Repositories\BishopRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BishopService
{
    protected BishopRepository $repository;

    public function __construct(BishopRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Get paginated bishops with filters
     */
    public function getPaginated(array $params)
    {
        $cacheKey = 'bishops.paginated.' . md5(json_encode($params));
        
        return Cache::remember($cacheKey, 300, function () use ($params) {
            return $this->repository->getBishopsPaginated($params);
        });
    }

    /**
     * Get bishop by ID with relationships
     */
    public function getById(string $id)
    {
        // Disabled cache for testing
        return $this->repository->findWithRelations($id);
    }

    /**
     * Create new bishop
     */
    public function create(array $data)
    {
        DB::beginTransaction();
        
        try {
            // ID is auto-increment, don't set it manually
            unset($data['id']);
            
            $bishop = $this->repository->create($data);
            
            $this->clearCache();
            
            DB::commit();
            
            return $bishop;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update bishop
     */
    public function update(string $id, array $data)
    {
        DB::beginTransaction();
        
        try {
            $bishop = $this->repository->update($id, $data);
            
            $this->clearCache($id);
            
            DB::commit();
            
            return $bishop;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Delete bishop
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
     * Get bishops by diocese
     */
    public function getByDiocese(string $dioceseId, bool $currentOnly = true)
    {
        $cacheKey = "bishops.diocese.{$dioceseId}." . ($currentOnly ? 'current' : 'all');
        
        return Cache::remember($cacheKey, 600, function () use ($dioceseId, $currentOnly) {
            return $this->repository->getByDiocese($dioceseId, $currentOnly);
        });
    }

    /**
     * Get bishops by title
     */
    public function getByTitle(string $titleId)
    {
        $cacheKey = "bishops.title.{$titleId}";
        
        return Cache::remember($cacheKey, 600, function () use ($titleId) {
            return $this->repository->getByTitle($titleId);
        });
    }

    /**
     * Get statistics
     */
    public function getStatistics()
    {
        return Cache::remember('bishops.statistics', 600, function () {
            return $this->repository->getStatistics();
        });
    }

    /**
     * Bulk import bishops
     */
    public function bulkImport(array $bishops)
    {
        DB::beginTransaction();
        
        try {
            $imported = 0;
            $errors = [];
            
            foreach ($bishops as $index => $bishopData) {
                try {
                    $this->repository->create($bishopData);
                    $imported++;
                } catch (\Exception $e) {
                    $errors[] = [
                        'row' => $index + 1,
                        'data' => $bishopData,
                        'error' => $e->getMessage(),
                    ];
                }
            }
            
            $this->clearCache();
            
            DB::commit();
            
            return [
                'imported' => $imported,
                'errors' => $errors,
                'total' => count($bishops),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Clear bishop cache
     */
    protected function clearCache(?string $bishopId = null): void
    {
        Cache::forget('bishops.statistics');
        
        if ($bishopId) {
            Cache::forget("bishop.{$bishopId}.full");
        }
        
        // Clear paginated cache (simplified - in production use tags)
        Cache::flush(); // Use cache tags in production
    }
}

