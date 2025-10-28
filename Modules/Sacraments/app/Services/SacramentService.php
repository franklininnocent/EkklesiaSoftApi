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
}

