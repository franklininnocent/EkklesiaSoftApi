<?php

namespace Modules\Sacraments\Repositories;

use Modules\Sacraments\Models\Sacrament;
use Illuminate\Pagination\LengthAwarePaginator;

class SacramentRepository
{
    public function __construct(protected Sacrament $model) {}

    public function getPaginated(array $params = []): LengthAwarePaginator
    {
        // Only eager load sacramentType - tenant is not needed for list view
        $query = $this->model->with(['sacramentType']);

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
        } elseif (!empty($params['date_from'])) {
            $query->where('date_administered', '>=', $params['date_from']);
        } elseif (!empty($params['date_to'])) {
            $query->where('date_administered', '<=', $params['date_to']);
        }

        if (!empty($params['minister_name'])) {
            $query->byMinisterName($params['minister_name']);
        }

        if (!empty($params['certificate_number'])) {
            $query->byCertificateNumber($params['certificate_number']);
        }

        if (!empty($params['book_number'])) {
            $query->byBookNumber($params['book_number']);
        }

        if (!empty($params['family_id'])) {
            $query->byFamily($params['family_id']);
        }

        if (!empty($params['bcc_id'])) {
            $query->byBCC($params['bcc_id']);
        }

        $perPage = $params['per_page'] ?? 20;
        $sortBy = $params['sort_by'] ?? 'date_administered';
        $sortDir = $params['sort_dir'] ?? 'desc';

        // Ensure we're only getting non-deleted records
        $query->whereNull('deleted_at');

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

    /**
     * Find multiple sacraments by IDs
     */
    public function findByIds(array $ids)
    {
        return $this->model->whereIn('id', $ids)->get();
    }
}


