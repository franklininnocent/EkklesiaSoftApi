<?php

namespace Modules\ApplicationAccess\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\ApplicationAccess\Models\ApplicationAccessSession;
use Modules\ApplicationAccess\Support\ApplicationAccessFilterCatalog;

class ApplicationAccessSessionQueryService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = ApplicationAccessSession::query()
            ->with(['user:id,name,email,tenant_id'])
            ->orderByDesc('started_at');

        $this->applyFilters($query, $filters);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function find(string $id): ?ApplicationAccessSession
    {
        return ApplicationAccessSession::query()
            ->with(['user:id,name,email,tenant_id'])
            ->find($id);
    }

    /**
     * @param  Builder<ApplicationAccessSession>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['status']) && ApplicationAccessFilterCatalog::isAllowed('status', (string) $filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['identity_type']) && ApplicationAccessFilterCatalog::isAllowed('identity_type', (string) $filters['identity_type'])) {
            $query->where('identity_type', $filters['identity_type']);
        }

        if (! empty($filters['access_context']) && ApplicationAccessFilterCatalog::isAllowed('access_context', (string) $filters['access_context'])) {
            $query->where('access_context', $filters['access_context']);
        }

        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', (int) $filters['tenant_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['ip_address'])) {
            $query->where('ip_address', (string) $filters['ip_address']);
        }

        if (! empty($filters['country'])) {
            $query->where('country', strtoupper((string) $filters['country']));
        }

        if (! empty($filters['started_from'])) {
            $query->where('started_at', '>=', $filters['started_from']);
        }

        if (! empty($filters['started_to'])) {
            $query->where('started_at', '<=', $filters['started_to']);
        }

        if (! empty($filters['q'])) {
            $term = (string) $filters['q'];
            $query->whereHas('user', function (Builder $userQuery) use ($term, $filters): void {
                $userQuery->where('name', 'like', '%'.$term.'%');
                if (! empty($filters['can_search_email'])) {
                    $userQuery->orWhere('email', 'like', '%'.$term.'%');
                }
            });
        }

        if (! empty($filters['email']) && ! empty($filters['can_search_email'])) {
            $query->whereHas('user', fn (Builder $userQuery) => $userQuery->where('email', 'like', '%'.$filters['email'].'%'));
        }
    }
}
