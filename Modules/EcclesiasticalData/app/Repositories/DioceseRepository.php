<?php

namespace Modules\EcclesiasticalData\Repositories;

use Modules\EcclesiasticalData\Models\DioceseManagement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DioceseRepository extends BaseRepository
{
    protected function makeModel(): Model
    {
        return new DioceseManagement();
    }

    /**
     * Get dioceses with pagination, search, and filters
     */
    public function getDiocesesPaginated(array $params): LengthAwarePaginator
    {
        $query = $this->model->with(['country', 'state', 'denomination']);

        // Apply search
        if (!empty($params['search'])) {
            $query->search($params['search']);
        }

        // Apply filters
        if (!empty($params['country_id'])) {
            $query->byCountryId($params['country_id']);
        }

        if (!empty($params['denomination_id'])) {
            $query->byDenomination($params['denomination_id']);
        }

        if (isset($params['is_active'])) {
            if ($params['is_active']) {
                $query->active();
            }
        }

        // Apply sorting
        $sortBy = $params['sort_by'] ?? 'name';
        $sortDir = $params['sort_dir'] ?? 'asc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = $params['per_page'] ?? 15;
        
        return $query->paginate($perPage);
    }

    /**
     * Get diocese with all relationships
     */
    public function findWithRelations(string $id)
    {
        return $this->model
            ->with([
                'country',
                'state',
                'denomination',
                'childArchdioceses',
                'parentArchdiocese'
            ])
            ->findOrFail($id);
    }

    /**
     * Get dioceses by country
     */
    public function getByCountry(int $countryId)
    {
        return $this->model
            ->byCountryId($countryId)
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * Get archdioceses (metropolitans) only
     * Identifies archdioceses by name pattern (contains "Archdiocese")
     */
    public function getArchdioceses()
    {
        return $this->model
            ->where('name', 'LIKE', '%Archdiocese%')
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * Get statistics
     */
    public function getStatistics(): array
    {
        return [
            'total' => $this->model->count(),
            'active' => $this->model->active()->count(),
            'archdioceses' => $this->model->where('name', 'LIKE', '%Archdiocese%')->count(),
            'dioceses' => $this->model->where('name', 'NOT LIKE', '%Archdiocese%')->count(),
            'by_country' => $this->model
                ->selectRaw('country_id, count(*) as total')
                ->groupBy('country_id')
                ->pluck('total', 'country_id'),
        ];
    }
}

