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
            'total_dioceses' => $this->model->count(),
            'active_dioceses' => $this->model->active()->count(),
            'inactive_dioceses' => $this->model->where('active', false)->count(),
            // Archdioceses have null parent_archdiocese_id, regular dioceses have a parent
            'total_archdioceses' => $this->model->whereNull('parent_archdiocese_id')->count(),
            'total_regular_dioceses' => $this->model->whereNotNull('parent_archdiocese_id')->count(),
            'by_country' => $this->model
                ->with('country:id,name')
                ->selectRaw('country_id, count(*) as total')
                ->groupBy('country_id')
                ->get()
                ->map(function ($item) {
                    return [
                        'country' => $item->country->name ?? 'Unknown',
                        'total' => $item->total
                    ];
                })
                ->values()
                ->toArray(),
            'by_denomination' => $this->model
                ->with('denomination:id,name')
                ->selectRaw('denomination_id, count(*) as total')
                ->groupBy('denomination_id')
                ->get()
                ->map(function ($item) {
                    return [
                        'denomination' => $item->denomination->name ?? 'Unknown',
                        'total' => $item->total
                    ];
                })
                ->values()
                ->toArray(),
            'recent_additions' => $this->model
                ->with(['country:id,name', 'denomination:id,name'])
                ->latest()
                ->limit(5)
                ->get(['id', 'name', 'country_id', 'denomination_id', 'parent_archdiocese_id', 'created_at'])
                ->map(function ($item) {
                    $data = $item->toArray();
                    // Add is_archdiocese based on parent_archdiocese_id
                    $data['is_archdiocese'] = is_null($item->parent_archdiocese_id);
                    return $data;
                })
                ->toArray(),
        ];
    }
}

