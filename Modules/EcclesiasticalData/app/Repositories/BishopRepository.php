<?php

namespace Modules\EcclesiasticalData\Repositories;

use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BishopRepository extends BaseRepository
{
    private const LIST_COLUMNS = [
        'id',
        'full_name',
        'normalized_name',
        'given_name',
        'family_name',
        'religious_name',
        'ecclesiastical_title_id',
        'archdiocese_id',
        'appointed_date',
        'date_of_birth',
        'ordained_priest_date',
        'ordained_bishop_date',
        'retired_date',
        'email',
        'phone',
        'education',
        'status',
        'is_current',
        'active',
        'photo_url',
        'photo_path',
        'coat_of_arms_path',
        'last_verified_at',
        'created_at',
        'updated_at',
    ];

    protected function makeModel(): Model
    {
        return new BishopManagement();
    }

    /**
     * Get bishops with pagination, search, and filters
     */
    public function getBishopsPaginated(array $params): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->select(self::LIST_COLUMNS)
            ->with([
                'archdiocese:id,name',
                'ecclesiasticalTitle:id,title',
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

        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        } elseif (isset($params['is_active'])) {
            if ($params['is_active']) {
                $query->active();
            }
        }

        if (array_key_exists('is_current', $params) && $params['is_current'] !== null && $params['is_current'] !== '') {
            $query->where('is_current', filter_var($params['is_current'], FILTER_VALIDATE_BOOLEAN));
        }

        // Apply sorting
        $sortBy = $params['sort_by'] ?? 'full_name';
        $sortDir = $params['sort_dir'] ?? 'asc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = min(max((int) ($params['per_page'] ?? 15), 1), 100);
        $page = $params['page'] ?? null;
        
        return $query->paginate($perPage, self::LIST_COLUMNS, 'page', $page);
    }

    /**
     * Get bishop with all relationships
     */
    public function findWithRelations(string $id)
    {
        return $this->model->newQuery()
            ->with([
                'archdiocese',
                'ecclesiasticalTitle',
                'appointments' => function ($query) {
                    $query->with(['diocese', 'ecclesiasticalTitle'])->orderBy('appointed_date', 'desc');
                },
            ])
            ->findOrFail($id);
    }

    /**
     * Get bishops by diocese
     */
    public function getByDiocese(string $dioceseId, bool $currentOnly = true)
    {
        return $this->model->newQuery()
            ->select(self::LIST_COLUMNS)
            ->byDiocese($dioceseId, $currentOnly)
            ->with([
                'archdiocese:id,name',
                'ecclesiasticalTitle:id,title',
            ])
            ->orderBy('full_name')
            ->limit(100)
            ->get();
    }

    /**
     * Get bishops by title
     */
    public function getByTitle(string $titleId)
    {
        return $this->model->newQuery()
            ->select(self::LIST_COLUMNS)
            ->byTitle($titleId)
            ->with([
                'archdiocese:id,name',
                'ecclesiasticalTitle:id,title',
            ])
            ->active()
            ->orderBy('full_name')
            ->limit(100)
            ->get();
    }

    /**
     * Get statistics
     */
    public function getStatistics(): array
    {
        $base = $this->model->newQuery();

        $counts = (clone $base)
            ->selectRaw("
                COUNT(*) as total_bishops,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_bishops,
                SUM(CASE WHEN status IN ('inactive', 'suspended', 'removed') THEN 1 ELSE 0 END) as inactive_bishops,
                SUM(CASE WHEN status = 'retired' THEN 1 ELSE 0 END) as retired_bishops
            ")
            ->first();

        return [
            'total_bishops' => (int) ($counts->total_bishops ?? 0),
            'active_bishops' => (int) ($counts->active_bishops ?? 0),
            'inactive_bishops' => (int) ($counts->inactive_bishops ?? 0),
            'retired_bishops' => (int) ($counts->retired_bishops ?? 0),
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
