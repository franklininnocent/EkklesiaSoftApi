<?php

namespace Modules\Sacraments\Repositories;

use Modules\Sacraments\Models\Sacrament;
use Illuminate\Pagination\LengthAwarePaginator;

class SacramentRepository
{
    public function __construct(protected Sacrament $model) {}

    public function getPaginated(array $params = []): LengthAwarePaginator
    {
        $query = $this->model->with(['sacramentType', 'tenant']);

        if (!empty($params['tenant_id'])) {
            $query->forTenant($params['tenant_id']);
        }

        if (!empty($params['sacrament_type_id'])) {
            $query->bySacramentType($params['sacrament_type_id']);
        }

        if (!empty($params['status'])) {
            $query->byStatus($params['status']);
        }

        if (!empty($params['search'])) {
            $query->searchRecipient($params['search']);
        }

        if (!empty($params['date_from']) && !empty($params['date_to'])) {
            $query->dateRange($params['date_from'], $params['date_to']);
        }

        $perPage = $params['per_page'] ?? 20;
        $sortBy = $params['sort_by'] ?? 'date_administered';
        $sortDir = $params['sort_dir'] ?? 'desc';

        return $query->orderBy($sortBy, $sortDir)->paginate($perPage);
    }

    public function create(array $data): Sacrament
    {
        return $this->model->create($data);
    }

    public function update(Sacrament $sacrament, array $data): Sacrament
    {
        $sacrament->update($data);
        return $sacrament->fresh();
    }

    public function delete(Sacrament $sacrament): bool
    {
        return $sacrament->delete();
    }

    public function findById(int $id): ?Sacrament
    {
        return $this->model->with(['sacramentType', 'tenant', 'creator', 'updater'])->find($id);
    }
}


