<?php

namespace Modules\Sacraments\Services;

use Modules\Sacraments\Repositories\SacramentRepository;
use Modules\Sacraments\Models\Sacrament;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class SacramentService
{
    public function __construct(protected SacramentRepository $repository) {}

    public function getAll(array $params = []): LengthAwarePaginator
    {
        // Generate cache key based on parameters
        $cacheKey = $this->getCacheKey($params);
        
        // Cache for 5 minutes (300 seconds) - adjust based on your needs
        // Only cache if there are no search/filter parameters that change frequently
        $shouldCache = empty($params['search']) && 
                      empty($params['date_from']) && 
                      empty($params['date_to']) &&
                      empty($params['minister_name']) &&
                      empty($params['certificate_number']) &&
                      empty($params['book_number']);
        
        if ($shouldCache) {
            return Cache::remember($cacheKey, 300, function () use ($params) {
                return $this->repository->getPaginated($params);
            });
        }
        
        // Don't cache if there are dynamic search/filter parameters
        return $this->repository->getPaginated($params);
    }

    /**
     * Generate cache key based on parameters
     */
    private function getCacheKey(array $params): string
    {
        $keyParts = [
            'sacraments',
            'tenant_' . ($params['tenant_id'] ?? 'all'),
            'page_' . ($params['page'] ?? 1),
            'per_page_' . ($params['per_page'] ?? 20),
            'type_' . ($params['sacrament_type_id'] ?? 'all'),
            'status_' . ($params['status'] ?? 'all'),
            'sort_' . ($params['sort_by'] ?? 'date_administered') . '_' . ($params['sort_dir'] ?? 'desc'),
        ];
        
        return implode('_', $keyParts);
    }

    /**
     * Clear cache for sacraments list
     */
    public function clearCache(?int $tenantId = null): void
    {
        if ($tenantId) {
            // Clear cache for specific tenant
            $pattern = "sacraments_tenant_{$tenantId}_*";
            Cache::flush(); // Note: Laravel doesn't support pattern-based cache clearing by default
            // For production, consider using Redis with pattern matching or a cache tag system
        } else {
            // Clear all sacrament-related cache
            // In production, use cache tags if available
            Cache::flush();
        }
    }

    public function getById(int $id): ?Sacrament
    {
        return $this->repository->findById($id);
    }

    public function create(array $data): Sacrament
    {
        $data['created_by'] = auth()->id();
        $sacrament = $this->repository->create($data);
        
        // Clear cache after creating
        $this->clearCache($data['tenant_id'] ?? null);
        
        return $sacrament;
    }

    public function update(int $id, array $data): ?Sacrament
    {
        $sacrament = $this->repository->findById($id);
        if (!$sacrament) {
            return null;
        }

        $data['updated_by'] = auth()->id();
        $updated = $this->repository->update($sacrament, $data);
        
        // Clear cache after updating
        $this->clearCache($sacrament->tenant_id ?? null);
        
        return $updated;
    }

    public function delete(int $id): bool
    {
        $sacrament = $this->repository->findById($id);
        if (!$sacrament) {
            return false;
        }

        $tenantId = $sacrament->tenant_id;
        $deleted = $this->repository->delete($sacrament);
        
        // Clear cache after deleting
        if ($deleted) {
            $this->clearCache($tenantId);
        }
        
        return $deleted;
    }

    /**
     * Get multiple sacraments by IDs
     */
    public function getByIds(array $ids)
    {
        return $this->repository->findByIds($ids);
    }

    /**
     * Bulk update status for multiple sacraments
     */
    public function bulkUpdateStatus(array $ids, string $status, ?int $updatedBy = null): int
    {
        $updatedBy = $updatedBy ?? auth()->id();
        
        // Get tenant IDs before update to clear cache
        $tenantIds = Sacrament::whereIn('id', $ids)
            ->distinct()
            ->pluck('tenant_id')
            ->filter()
            ->unique()
            ->toArray();
        
        $updated = Sacrament::whereIn('id', $ids)
            ->update([
                'status' => $status,
                'updated_by' => $updatedBy,
                'updated_at' => now()
            ]);
        
        // Clear cache for affected tenants
        foreach ($tenantIds as $tenantId) {
            $this->clearCache($tenantId);
        }
        
        return $updated;
    }

    /**
     * Bulk delete multiple sacraments
     */
    public function bulkDelete(array $ids): int
    {
        // Get tenant IDs before deletion to clear cache
        $tenantIds = Sacrament::whereIn('id', $ids)
            ->distinct()
            ->pluck('tenant_id')
            ->filter()
            ->unique()
            ->toArray();
        
        $deleted = 0;
        foreach ($ids as $id) {
            $sacrament = $this->repository->findById($id);
            if ($sacrament && $this->repository->delete($sacrament)) {
                $deleted++;
            }
        }
        
        // Clear cache for affected tenants
        foreach ($tenantIds as $tenantId) {
            $this->clearCache($tenantId);
        }
        
        return $deleted;
    }
}


