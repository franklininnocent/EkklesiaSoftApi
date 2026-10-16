<?php

namespace Modules\ApplicationAccess\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\ApplicationAccess\Models\ApplicationSecurityEvent;
use Modules\ApplicationAccess\Models\ApplicationSecuritySignal;
use Modules\ApplicationAccess\Support\ApplicationAccessFilterCatalog;

class ApplicationAccessSecurityQueryService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateSecurityEvents(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = ApplicationSecurityEvent::query()
            ->orderByDesc('detected_at')
            ->orderByDesc('id');

        $this->applySecurityEventFilters($query, $filters);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateSignals(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = ApplicationSecuritySignal::query()
            ->orderByDesc('last_seen')
            ->orderByDesc('id');

        $this->applySignalFilters($query, $filters);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  Builder<ApplicationSecurityEvent>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySecurityEventFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['event_type']) && ApplicationAccessFilterCatalog::isAllowed('event_type', (string) $filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        } elseif (! empty($filters['event_type'])) {
            $allowed = ApplicationAccessFilterCatalog::SECURITY_EVENT_TYPES;
            if (in_array($filters['event_type'], $allowed, true)) {
                $query->where('event_type', $filters['event_type']);
            }
        }

        if (! empty($filters['severity']) && ApplicationAccessFilterCatalog::isAllowed('severity', (string) $filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (! empty($filters['actor_user_id'])) {
            $query->where('actor_user_id', (int) $filters['actor_user_id']);
        }

        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', (int) $filters['tenant_id']);
        }

        if (! empty($filters['source_ip'])) {
            $query->where('source_ip', (string) $filters['source_ip']);
        }

        if (! empty($filters['from'])) {
            $query->where('detected_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('detected_at', '<=', $filters['to']);
        }
    }

    /**
     * @param  Builder<ApplicationSecuritySignal>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySignalFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['signal_type']) && ApplicationAccessFilterCatalog::isAllowed('signal_type', (string) $filters['signal_type'])) {
            $query->where('signal_type', $filters['signal_type']);
        }

        if (! empty($filters['source_ip'])) {
            $query->where('source_ip', (string) $filters['source_ip']);
        }

        if (! empty($filters['from'])) {
            $query->where('last_seen', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('last_seen', '<=', $filters['to']);
        }
    }
}
