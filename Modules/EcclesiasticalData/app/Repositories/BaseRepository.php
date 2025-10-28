<?php

namespace Modules\EcclesiasticalData\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\EcclesiasticalData\Repositories\Contracts\BaseRepositoryInterface;

abstract class BaseRepository implements BaseRepositoryInterface
{
    protected Model $model;

    public function __construct()
    {
        $this->model = $this->makeModel();
    }

    /**
     * Make model instance
     */
    abstract protected function makeModel(): Model;

    /**
     * Get all records
     */
    public function all(array $columns = ['*']): Collection
    {
        return $this->model->select($columns)->get();
    }

    /**
     * Find a record by ID
     */
    public function find(string $id, array $columns = ['*'])
    {
        return $this->model->select($columns)->find($id);
    }

    /**
     * Find or fail
     */
    public function findOrFail(string $id, array $columns = ['*'])
    {
        return $this->model->select($columns)->findOrFail($id);
    }

    /**
     * Create a new record
     */
    public function create(array $data)
    {
        return $this->model->create($data);
    }

    /**
     * Update a record
     */
    public function update(string $id, array $data)
    {
        $record = $this->findOrFail($id);
        $record->update($data);
        return $record->fresh();
    }

    /**
     * Delete a record
     */
    public function delete(string $id): bool
    {
        return $this->findOrFail($id)->delete();
    }

    /**
     * Get paginated results with filters
     */
    public function paginate(
        int $perPage = 15,
        array $columns = ['*'],
        array $filters = []
    ): LengthAwarePaginator {
        $query = $this->model->select($columns);
        
        $query = $this->applyFilters($query, $filters);
        
        return $query->paginate($perPage);
    }

    /**
     * Search records
     */
    public function search(string $query, array $columns = ['*']): Collection
    {
        return $this->model->search($query)->get($columns);
    }

    /**
     * Count records
     */
    public function count(array $filters = []): int
    {
        $query = $this->model->query();
        $query = $this->applyFilters($query, $filters);
        return $query->count();
    }

    /**
     * Check if record exists
     */
    public function exists(string $id): bool
    {
        return $this->model->where('id', $id)->exists();
    }

    /**
     * Apply filters to query
     */
    protected function applyFilters($query, array $filters)
    {
        foreach ($filters as $key => $value) {
            if (method_exists($this->model, 'scope' . ucfirst($key))) {
                $query->{$key}($value);
            }
        }
        
        return $query;
    }

    /**
     * Get with relationships
     */
    public function with(array $relations)
    {
        return $this->model->with($relations);
    }
}

