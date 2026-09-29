<?php

namespace Modules\MinistriesAssociations\Services\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Modules\MinistriesAssociations\Models\MinistriesAuditLog;
use Modules\MinistriesAssociations\Support\AdminMinistriesDefinitions;
use Modules\MinistriesAssociations\Support\AdminMinistriesWindow;
use Modules\Tenants\Models\TenantSubscriptionAudit;

/**
 * Cross-tenant Ministries activity browser + entitlement governance slice.
 */
class AdminMinistriesAuditService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, window: array<string, mixed>, governance: array<string, mixed>}
     */
    public function list(array $filters): array
    {
        $window = $this->resolveWindow($filters);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));

        $query = MinistriesAuditLog::query()
            ->with([
                'actor:id,name',
                'tenant:id,name,slug',
            ])
            ->where('created_at', '>=', $window->currentStart)
            ->where('created_at', '<=', $window->currentEnd)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', (int) $filters['tenant_id']);
        }

        if (! empty($filters['event'])) {
            $query->where('event', (string) $filters['event']);
        } elseif (! empty($filters['meaningful_only'])) {
            $query->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS);
        }

        if (! empty($filters['target_type'])) {
            $query->where('target_type', (string) $filters['target_type']);
        }

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', (string) $filters['organization_id']);
        }

        if (! empty($filters['actor_user_id'])) {
            $query->where('actor_user_id', (int) $filters['actor_user_id']);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $data = collect($paginator->items())->map(static function (MinistriesAuditLog $log): array {
            return [
                'id' => $log->id,
                'occurred_at' => optional($log->created_at)?->toIso8601String(),
                'event' => $log->event,
                'event_label' => str_replace(['.', '_'], [' — ', ' '], $log->event),
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'organization_id' => $log->organization_id,
                'tenant' => $log->tenant ? [
                    'id' => $log->tenant->id,
                    'name' => $log->tenant->name,
                    'slug' => $log->tenant->slug,
                ] : null,
                'actor' => $log->actor ? [
                    'id' => $log->actor->id,
                    'name' => $log->actor->name,
                ] : null,
            ];
        })->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
            ],
            'window' => [
                'days' => $window->days,
                'current_start' => $window->currentStart->toIso8601String(),
                'current_end' => $window->currentEnd->toIso8601String(),
            ],
            'event_options' => AdminMinistriesDefinitions::MEANINGFUL_EVENTS,
            'governance' => $this->governanceSlice($window, isset($filters['tenant_id']) ? (int) $filters['tenant_id'] : null),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function resolveWindow(array $filters): AdminMinistriesWindow
    {
        $from = isset($filters['date_from']) && $filters['date_from'] !== ''
            ? CarbonImmutable::parse((string) $filters['date_from'], 'UTC')->startOfDay()
            : null;
        $to = isset($filters['date_to']) && $filters['date_to'] !== ''
            ? CarbonImmutable::parse((string) $filters['date_to'], 'UTC')->endOfDay()
            : null;

        if ($from !== null || $to !== null) {
            $end = $to ?? CarbonImmutable::now('UTC');
            $start = $from ?? $end->subDays(AdminMinistriesDefinitions::DEFAULT_ACTIVITY_WINDOW_DAYS);

            return AdminMinistriesWindow::fromRange($start, $end);
        }

        $days = isset($filters['window_days'])
            ? (int) $filters['window_days']
            : AdminMinistriesDefinitions::DEFAULT_ACTIVITY_WINDOW_DAYS;

        return AdminMinistriesWindow::fromDays($days);
    }

    /**
     * Entitlement changes affecting ministries_associations when subscription audit snapshots include features.
     *
     * @return array<string, mixed>
     */
    private function governanceSlice(AdminMinistriesWindow $window, ?int $tenantId): array
    {
        if (! Schema::hasTable('tenant_subscription_audits')) {
            return [
                'available' => false,
                'reason' => 'tenant_subscription_audits table is not available in this environment.',
                'items' => [],
            ];
        }

        $query = TenantSubscriptionAudit::query()
            ->with(['tenant:id,name,slug', 'actor:id,name'])
            ->where('created_at', '>=', $window->currentStart)
            ->where('created_at', '<=', $window->currentEnd)
            ->orderByDesc('created_at')
            ->limit(50);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $items = [];
        foreach ($query->get() as $audit) {
            $beforeFeatures = is_array($audit->before_state['features'] ?? null)
                ? $audit->before_state['features']
                : [];
            $afterFeatures = is_array($audit->after_state['features'] ?? null)
                ? $audit->after_state['features']
                : [];

            $had = in_array(AdminMinistriesDefinitions::FEATURE_KEY, $beforeFeatures, true);
            $has = in_array(AdminMinistriesDefinitions::FEATURE_KEY, $afterFeatures, true);

            if ($had === $has) {
                continue;
            }

            $items[] = [
                'id' => $audit->id,
                'occurred_at' => optional($audit->created_at)?->toIso8601String(),
                'operation' => $audit->operation,
                'change' => $has ? 'enabled' : 'disabled',
                'source' => $audit->source,
                'reason' => $audit->reason,
                'tenant' => $audit->tenant ? [
                    'id' => $audit->tenant->id,
                    'name' => $audit->tenant->name,
                    'slug' => $audit->tenant->slug,
                ] : null,
                'actor' => $audit->actor ? [
                    'id' => $audit->actor->id,
                    'name' => $audit->actor->name,
                ] : null,
            ];
        }

        return [
            'available' => true,
            'reason' => null,
            'items' => $items,
            'note' => 'Shows subscription audits where ministries_associations entitlement toggled in feature snapshots.',
        ];
    }
}
