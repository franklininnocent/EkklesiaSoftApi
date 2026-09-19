<?php

namespace Modules\Notifications\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Notifications\Models\UserNotification;
use Modules\Notifications\Support\InboxContext;
use Modules\Notifications\Support\InboxScope;

class InboxQueryService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: Collection<int, UserNotification>, next_cursor: ?string, has_more: bool, per_page: int}
     */
    public function list(InboxContext $context, array $filters, int $perPage): array
    {
        $query = UserNotification::query()
            ->with(['event.definition'])
            ->where('user_id', $context->userId)
            ->where('inbox_scope', $context->scope->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($context->scope === InboxScope::Tenant) {
            $query->where('tenant_id', $context->tenantId);
        } else {
            $query->whereNull('tenant_id');
        }

        $this->applyViewFilter($query, (string) ($filters['view'] ?? 'all'));
        $this->applyFilters($query, $filters);
        $this->applyCursor($query, $filters['cursor'] ?? null);

        $rows = $query->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $data = $rows->take($perPage)->values();

        $nextCursor = null;
        if ($hasMore && $data->isNotEmpty()) {
            $last = $data->last();
            $nextCursor = base64_encode(json_encode([
                'created_at' => $last->created_at?->toIso8601String(),
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

    public function findForUser(InboxContext $context, string $id): ?UserNotification
    {
        $query = UserNotification::query()
            ->with(['event.definition'])
            ->where('id', $id)
            ->where('user_id', $context->userId)
            ->where('inbox_scope', $context->scope->value);

        if ($context->scope === InboxScope::Tenant) {
            $query->where('tenant_id', $context->tenantId);
        } else {
            $query->whereNull('tenant_id');
        }

        return $query->first();
    }

    /**
     * @param  Builder<UserNotification>  $query
     */
    private function applyViewFilter(Builder $query, string $view): void
    {
        match ($view) {
            'unread' => $query->where('status', 'unread')->whereNull('archived_at'),
            'archived' => $query->whereNotNull('archived_at'),
            'mentions' => $query->where('is_mention', true)->whereNull('archived_at'),
            'action_required' => $query->where('action_status', 'required')->whereNull('archived_at'),
            default => $query->whereNull('archived_at'),
        };
    }

    /**
     * @param  Builder<UserNotification>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['category'])) {
            $query->whereHas('event', fn ($q) => $q->where('category', $filters['category']));
        }

        if (! empty($filters['module'])) {
            $query->whereHas('event', fn ($q) => $q->where('module', $filters['module']));
        }

        if (! empty($filters['priority'])) {
            $query->whereHas('event', fn ($q) => $q->where('priority', $filters['priority']));
        }

        if (! empty($filters['q']) && strlen((string) $filters['q']) >= 2) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']).'%';
            $query->whereHas('event', fn ($q) => $q->where(function ($inner) use ($term) {
                $inner->where('title', 'ilike', $term)->orWhere('body', 'ilike', $term);
            }));
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }
    }

    /**
     * @param  Builder<UserNotification>  $query
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

        if (! is_array($decoded) || empty($decoded['created_at']) || empty($decoded['id'])) {
            return;
        }

        $createdAt = $decoded['created_at'];
        $id = (string) $decoded['id'];

        $query->where(function (Builder $builder) use ($createdAt, $id): void {
            $builder->where('created_at', '<', $createdAt)
                ->orWhere(function (Builder $inner) use ($createdAt, $id): void {
                    $inner->where('created_at', '=', $createdAt)->where('id', '<', $id);
                });
        });
    }
}
