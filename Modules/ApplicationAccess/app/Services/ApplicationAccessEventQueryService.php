<?php

namespace Modules\ApplicationAccess\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\ApplicationAccess\Models\ApplicationAccessEvent;
use Modules\ApplicationAccess\Support\ApplicationAccessFilterCatalog;

class ApplicationAccessEventQueryService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = ApplicationAccessEvent::query()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $this->applyFilters($query, $filters);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: Collection<int, ApplicationAccessEvent>, next_cursor: ?string, has_more: bool, per_page: int}
     */
    public function timelineForSession(string $sessionId, array $filters, int $perPage): array
    {
        $query = ApplicationAccessEvent::query()
            ->where('access_session_id', $sessionId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $this->applyCursor($query, $filters['cursor'] ?? null);

        $rows = $query->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $data = $rows->take($perPage)->values();

        $nextCursor = null;
        if ($hasMore && $data->isNotEmpty()) {
            $last = $data->last();
            $nextCursor = base64_encode(json_encode([
                'occurred_at' => $last->occurred_at?->toIso8601String(),
                'id' => $last->id,
            ], JSON_THROW_ON_ERROR));
        }

        return [
            'data' => $data,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param  Builder<ApplicationAccessEvent>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['event_type']) && ApplicationAccessFilterCatalog::isAllowed('event_type', (string) $filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }

        if (! empty($filters['action']) && ApplicationAccessFilterCatalog::isAllowed('action', (string) $filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['authorization_result']) && ApplicationAccessFilterCatalog::isAllowed('authorization_result', (string) $filters['authorization_result'])) {
            $query->where('authorization_result', $filters['authorization_result']);
        }

        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', (int) $filters['tenant_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['access_session_id'])) {
            $query->where('access_session_id', (string) $filters['access_session_id']);
        }

        if (! empty($filters['ip_address'])) {
            $query->where('ip_address', (string) $filters['ip_address']);
        }

        if (! empty($filters['module_code'])) {
            $query->where('module_code', (string) $filters['module_code']);
        }

        if (! empty($filters['from'])) {
            $query->where('occurred_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('occurred_at', '<=', $filters['to']);
        }
    }

    /**
     * @param  Builder<ApplicationAccessEvent>  $query
     */
    private function applyCursor(Builder $query, ?string $cursor): void
    {
        if ($cursor === null || $cursor === '') {
            return;
        }

        try {
            $decoded = json_decode(base64_decode($cursor, true) ?: '', true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }

        if (! is_array($decoded) || empty($decoded['occurred_at']) || empty($decoded['id'])) {
            return;
        }

        $occurredAt = $decoded['occurred_at'];
        $id = (string) $decoded['id'];

        $query->where(function (Builder $builder) use ($occurredAt, $id): void {
            $builder->where('occurred_at', '<', $occurredAt)
                ->orWhere(function (Builder $inner) use ($occurredAt, $id): void {
                    $inner->where('occurred_at', $occurredAt)
                        ->where('id', '<', $id);
                });
        });
    }
}
