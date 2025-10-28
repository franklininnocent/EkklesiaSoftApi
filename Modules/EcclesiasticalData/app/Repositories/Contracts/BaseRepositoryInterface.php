<?php

namespace Modules\EcclesiasticalData\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface BaseRepositoryInterface
{
    /**
     * Get all records
     */
    public function all(array $columns = ['*']): Collection;

    /**
     * Find a record by ID
     */
    public function find(string $id, array $columns = ['*']);

    /**
     * Find or fail
     */
    public function findOrFail(string $id, array $columns = ['*']);

    /**
     * Create a new record
     */
    public function create(array $data);

    /**
     * Update a record
     */
    public function update(string $id, array $data);

    /**
     * Delete a record
     */
    public function delete(string $id): bool;

    /**
     * Get paginated results with filters
     */
    public function paginate(
        int $perPage = 15,
        array $columns = ['*'],
        array $filters = []
    ): LengthAwarePaginator;

    /**
     * Search records
     */
    public function search(string $query, array $columns = ['*']): Collection;

    /**
     * Count records
     */
    public function count(array $filters = []): int;

    /**
     * Check if record exists
     */
    public function exists(string $id): bool;
}

