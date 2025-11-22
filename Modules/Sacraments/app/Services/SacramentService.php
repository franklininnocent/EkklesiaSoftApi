<?php

namespace Modules\Sacraments\Services;

use Modules\Sacraments\Repositories\SacramentRepository;
use Modules\Sacraments\Models\Sacrament;
use Illuminate\Pagination\LengthAwarePaginator;

class SacramentService
{
    public function __construct(protected SacramentRepository $repository) {}

    public function getAll(array $params = []): LengthAwarePaginator
    {
        return $this->repository->getPaginated($params);
    }

    public function getById(int $id): ?Sacrament
    {
        return $this->repository->findById($id);
    }

    public function create(array $data): Sacrament
    {
        $data['created_by'] = auth()->id();
        return $this->repository->create($data);
    }

    public function update(int $id, array $data): ?Sacrament
    {
        $sacrament = $this->repository->findById($id);
        if (!$sacrament) {
            return null;
        }

        $data['updated_by'] = auth()->id();
        return $this->repository->update($sacrament, $data);
    }

    public function delete(int $id): bool
    {
        $sacrament = $this->repository->findById($id);
        if (!$sacrament) {
            return false;
        }

        return $this->repository->delete($sacrament);
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
        
        return Sacrament::whereIn('id', $ids)
            ->update([
                'status' => $status,
                'updated_by' => $updatedBy,
                'updated_at' => now()
            ]);
    }

    /**
     * Bulk delete multiple sacraments
     */
    public function bulkDelete(array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            $sacrament = $this->repository->findById($id);
            if ($sacrament && $this->repository->delete($sacrament)) {
                $deleted++;
            }
        }
        return $deleted;
    }
}


