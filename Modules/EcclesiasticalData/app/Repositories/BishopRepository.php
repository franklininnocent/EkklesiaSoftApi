<?php

namespace Modules\EcclesiasticalData\Repositories;

use Modules\EcclesiasticalData\Models\BishopManagement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BishopRepository extends BaseRepository
{
    protected function makeModel(): Model
    {
        return new BishopManagement();
    }

    /**
     * Get bishops with pagination, search, and filters
     */
    public function getBishopsPaginated(array $params): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->with([
            'archdiocese'
        ]);

        // Apply search
        if (!empty($params['search'])) {
            $query->search($params['search']);
        }

        // Apply filters
        if (!empty($params['diocese_id'])) {
            $query->byDiocese($params['diocese_id']);
        }

        if (!empty($params['title_id'])) {
            $query->byTitle($params['title_id']);
        }

        if (isset($params['is_active'])) {
            if ($params['is_active']) {
                $query->active();
            }
        }

        // Apply sorting
        $sortBy = $params['sort_by'] ?? 'full_name';
        $sortDir = $params['sort_dir'] ?? 'asc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = $params['per_page'] ?? 15;
        
        return $query->paginate($perPage);
    }

    /**
     * Get bishop with all relationships
     */
    public function findWithRelations(string $id)
    {
        return $this->model->newQuery()
            ->with([
                'archdiocese',
                'appointments' => function ($query) {
                    $query->with(['diocese'])->orderBy('appointed_date', 'desc');
                },
                'qualityIssues' => function ($query) {
                    $query->where('resolved_at', null); // unresolved issues
                }
            ])
            ->findOrFail($id);
    }

    /**
     * Get bishops by diocese
     */
    public function getByDiocese(string $dioceseId, bool $currentOnly = true)
    {
        $query = $this->model->newQuery()->byDiocese($dioceseId);
        
        if ($currentOnly) {
            $query->whereHas('appointments', function ($q) {
                $q->where('is_current', true);
            });
        }
        
        return $query->get();
    }

    /**
     * Get bishops by title
     */
    public function getByTitle(string $titleId)
    {
        return $this->model->newQuery()
            ->byTitle($titleId)
            ->active()
            ->orderBy('full_name')
            ->get();
    }

    /**
     * Get statistics
     */
    public function getStatistics(): array
    {
        return [
            'total_bishops' => $this->model->newQuery()->count(),
            'active_bishops' => $this->model->newQuery()->active()->count(),
            'inactive_bishops' => $this->model->newQuery()->where('status', 'inactive')->count(),
            'retired_bishops' => $this->model->newQuery()->where('status', 'retired')->count(),
            'by_title' => $this->model->newQuery()
                ->with('ecclesiasticalTitle:id,title')
                ->selectRaw('ecclesiastical_title_id, count(*) as total')
                ->whereNotNull('ecclesiastical_title_id')
                ->groupBy('ecclesiastical_title_id')
                ->get()
                ->map(function ($item) {
                    return [
                        'title' => $item->ecclesiasticalTitle->title ?? 'Unknown',
                        'total' => $item->total
                    ];
                })
                ->values()
                ->toArray(),
            'by_diocese' => $this->model->newQuery()
                ->with('archdiocese:id,name')
                ->selectRaw('archdiocese_id, count(*) as total')
                ->whereNotNull('archdiocese_id')
                ->groupBy('archdiocese_id')
                ->get()
                ->map(function ($item) {
                    return [
                        'diocese' => $item->archdiocese->name ?? 'Unknown',
                        'total' => $item->total
                    ];
                })
                ->sortByDesc('total')
                ->take(10)
                ->values()
                ->toArray(),
            'recent_additions' => $this->model->newQuery()
                ->with(['ecclesiasticalTitle:id,title', 'archdiocese:id,name', 'country:id,name'])
                ->latest()
                ->limit(5)
                ->get(['id', 'full_name', 'ecclesiastical_title_id', 'archdiocese_id', 'birth_country_id', 'appointed_date', 'status', 'created_at'])
                ->toArray(),
        ];
    }
}

