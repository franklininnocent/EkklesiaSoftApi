<?php

namespace Modules\SupportTickets\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\SupportTickets\Exceptions\SupportTicketException;
use Modules\SupportTickets\Jobs\MarkSlaBreachJob;
use Modules\SupportTickets\Jobs\MarkSlaWarningJob;
use Modules\SupportTickets\Models\SupportQueue;
use Modules\SupportTickets\Models\SupportRequestType;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Support\ResolvedByActor;
use Modules\SupportTickets\Support\TenantResolutionCategory;
use Modules\SupportTickets\Support\TicketEventType;
use Modules\SupportTickets\Support\TicketPriority;
use Modules\SupportTickets\Support\TicketStatus;
use Modules\Tenants\Support\TenantContext;

class SupportTicketService
{
    public function __construct(
        private readonly TicketEventRecorder $events,
        private readonly TicketStatusMachine $statusMachine,
        private readonly SlaCalculator $sla,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateForTenant(
        int $tenantId,
        User $actor,
        array $filters,
        int $perPage = 20,
        bool $canViewAll = false,
    ): LengthAwarePaginator {
        $query = $this->baseListQuery($tenantId)
            ->orderByDesc('updated_at');

        $this->applyTenantVisibility($query, $actor, $canViewAll, $filters);
        $this->applyListFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateForOps(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = SupportTicket::query()
            ->with($this->listRelations())
            ->with(['tenant:id,name'])
            ->orderByDesc('updated_at');

        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', (int) $filters['tenant_id']);
        }

        $this->applyListFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * @return array<string, int>
     */
    public function dashboardForTenant(int $tenantId, User $actor, bool $canViewAll): array
    {
        $query = SupportTicket::query()->forTenant($tenantId);
        $this->applyTenantVisibility($query, $actor, $canViewAll, ['scope' => $canViewAll ? 'all' : 'mine']);

        $rows = (clone $query)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $openStatuses = TicketStatus::open();
        $open = 0;
        foreach ($openStatuses as $status) {
            $open += (int) ($rows[$status] ?? 0);
        }

        $slaAtRisk = (clone $query)
            ->whereNotIn('status', [TicketStatus::RESOLVED, TicketStatus::CLOSED, TicketStatus::CANCELLED])
            ->where(function (Builder $q): void {
                $q->where('resolution_due_at', '<=', now()->addHour())
                    ->orWhere('first_response_due_at', '<=', now()->addHour());
            })
            ->count();

        return [
            'open' => $open,
            'awaiting_ekklesia' => (int) ($rows[TicketStatus::AWAITING_EKKLESIA] ?? 0)
                + (int) ($rows[TicketStatus::NEW] ?? 0)
                + (int) ($rows[TicketStatus::IN_PROGRESS] ?? 0),
            'awaiting_you' => (int) ($rows[TicketStatus::AWAITING_YOU] ?? 0),
            'resolved' => (int) ($rows[TicketStatus::RESOLVED] ?? 0),
            'total' => (int) (clone $query)->count(),
            'sla_at_risk' => $slaAtRisk,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function dashboardForOps(): array
    {
        $rows = SupportTicket::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $openStatuses = array_diff(TicketStatus::open(), [TicketStatus::DRAFT]);
        $open = 0;
        foreach ($openStatuses as $status) {
            $open += (int) ($rows[$status] ?? 0);
        }

        return [
            'open' => $open,
            'unassigned' => SupportTicket::query()
                ->whereNull('assigned_agent_id')
                ->whereNotIn('status', [TicketStatus::RESOLVED, TicketStatus::CLOSED, TicketStatus::CANCELLED])
                ->count(),
            'critical' => SupportTicket::query()
                ->where('priority', TicketPriority::CRITICAL)
                ->whereNotIn('status', [TicketStatus::RESOLVED, TicketStatus::CLOSED, TicketStatus::CANCELLED])
                ->count(),
            'urgent' => SupportTicket::query()
                ->where('priority', TicketPriority::URGENT)
                ->whereNotIn('status', [TicketStatus::RESOLVED, TicketStatus::CLOSED, TicketStatus::CANCELLED])
                ->count(),
            'sla_at_risk' => SupportTicket::query()
                ->whereNotIn('status', [TicketStatus::RESOLVED, TicketStatus::CLOSED, TicketStatus::CANCELLED])
                ->where(function (Builder $q): void {
                    $q->where('resolution_due_at', '<=', now()->addHour())
                        ->orWhere('first_response_due_at', '<=', now()->addHour());
                })
                ->count(),
            'waiting_for_customer' => (int) ($rows[TicketStatus::AWAITING_YOU] ?? 0),
        ];
    }

    public function findForTenant(int $tenantId, string $identifier, bool $withDetail = true): SupportTicket
    {
        $query = SupportTicket::query()->forTenant($tenantId);

        if ($withDetail) {
            $query->with($this->detailRelations());
        } else {
            $query->with($this->listRelations());
        }

        $ticket = $this->resolveIdentifier($query, $identifier);

        if (! $ticket) {
            throw new SupportTicketException('Support ticket not found.', 404);
        }

        return $ticket;
    }

    public function findForOps(string $identifier, bool $withDetail = true): SupportTicket
    {
        $query = SupportTicket::query();

        if ($withDetail) {
            $query->with(array_merge($this->detailRelations(), ['tenant:id,name']));
        } else {
            $query->with(array_merge($this->listRelations(), ['tenant:id,name']));
        }

        $ticket = $this->resolveIdentifier($query, $identifier);

        if (! $ticket) {
            throw new SupportTicketException('Support ticket not found.', 404);
        }

        return $ticket;
    }

    public function assertTenantCanAccess(SupportTicket $ticket, User $user, bool $canViewAll): void
    {
        $context = app(TenantContext::class);

        if ($context->isSupportSession() && $this->actorHasOpsView($user)) {
            if ((int) $ticket->tenant_id !== (int) $context->requireEffectiveTenantId()) {
                throw new SupportTicketException('Support ticket not found.', 404);
            }

            return;
        }

        if ((int) $ticket->tenant_id !== (int) $user->tenant_id) {
            throw new SupportTicketException('Support ticket not found.', 404);
        }

        if ($canViewAll) {
            return;
        }

        if ((int) $ticket->requester_user_id === (int) $user->id) {
            return;
        }

        if ($ticket->isParticipant((int) $user->id)) {
            return;
        }

        throw new SupportTicketException('Support ticket not found.', 404);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(int $tenantId, User $actor, array $payload): SupportTicket
    {
        $requestType = SupportRequestType::query()
            ->where('id', $payload['request_type_id'])
            ->where('active', true)
            ->first();

        if (! $requestType) {
            throw new SupportTicketException('Invalid request type.', 422);
        }

        $priority = $payload['priority'] ?? TicketPriority::NORMAL;
        if (! in_array($priority, TicketPriority::all(), true)) {
            throw new SupportTicketException('Invalid priority.', 422);
        }

        $submit = (bool) ($payload['submit'] ?? true);
        $status = $submit ? TicketStatus::NEW : TicketStatus::DRAFT;

        $queueId = $this->resolveQueueId($payload['queue_id'] ?? null, $requestType->slug);

        return DB::transaction(function () use ($tenantId, $actor, $payload, $requestType, $priority, $status, $queueId, $submit): SupportTicket {
            $ticket = SupportTicket::query()->create([
                'ticket_number' => 'ES-PENDING',
                'tenant_id' => $tenantId,
                'requester_user_id' => $actor->id,
                'request_type_id' => $requestType->id,
                'category_id' => $payload['category_id'] ?? null,
                'subcategory_id' => $payload['subcategory_id'] ?? null,
                'queue_id' => $queueId,
                'subject' => $this->plainText((string) $payload['subject']),
                'description' => $this->plainText((string) $payload['description']),
                'steps_to_reproduce' => isset($payload['steps_to_reproduce'])
                    ? $this->plainText((string) $payload['steps_to_reproduce']) : null,
                'expected_result' => isset($payload['expected_result'])
                    ? $this->plainText((string) $payload['expected_result']) : null,
                'actual_result' => isset($payload['actual_result'])
                    ? $this->plainText((string) $payload['actual_result']) : null,
                'error_message' => isset($payload['error_message'])
                    ? $this->plainText((string) $payload['error_message']) : null,
                'bug_details' => $payload['bug_details'] ?? null,
                'business_impact' => $payload['business_impact'] ?? null,
                'affected_module' => $payload['affected_module'] ?? null,
                'occurrence_at' => $payload['occurrence_at'] ?? null,
                'priority' => $priority,
                'status' => $status,
            ]);

            $ticket->ticket_number = SupportTicket::formatTicketNumber($ticket->id);
            if ($submit) {
                $this->sla->applyDueDates($ticket);
            }
            $ticket->save();

            $this->events->record($ticket, (int) $actor->id, TicketEventType::CREATED);
            if ($submit) {
                $this->events->record($ticket, (int) $actor->id, TicketEventType::SUBMITTED);
                $this->scheduleSlaJobs($ticket);
            }

            return $ticket->fresh($this->detailRelations());
        });
    }

    public function assign(
        SupportTicket $ticket,
        User $actor,
        ?int $assignedAgentId,
        ?int $queueId,
        ?string $reason = null,
    ): SupportTicket {
        return DB::transaction(function () use ($ticket, $actor, $assignedAgentId, $queueId, $reason): SupportTicket {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ($assignedAgentId !== null) {
                $this->assertPlatformAgent($assignedAgentId);
                $locked->assigned_agent_id = $assignedAgentId;
            } elseif ($locked->assigned_agent_id === null) {
                $this->assertPlatformAgent((int) $actor->id);
                $locked->assigned_agent_id = (int) $actor->id;
            }

            if ($queueId !== null) {
                $locked->queue_id = $queueId;
            }

            $locked->save();

            $this->events->record(
                $locked,
                (int) $actor->id,
                TicketEventType::ASSIGNED,
                null,
                (string) $locked->assigned_agent_id,
                $reason,
            );

            if ($locked->status === TicketStatus::NEW) {
                return $this->transition($locked, $actor, TicketStatus::IN_PROGRESS, $reason);
            }

            return $locked->fresh($this->detailRelations());
        });
    }

    public function changePriority(SupportTicket $ticket, User $actor, string $priority): SupportTicket
    {
        if (! in_array($priority, TicketPriority::all(), true)) {
            throw new SupportTicketException('Invalid priority.', 422);
        }

        $old = $ticket->priority;
        if ($old === $priority) {
            return $ticket;
        }

        $ticket->priority = $priority;
        $ticket->save();

        $this->events->record($ticket, (int) $actor->id, TicketEventType::PRIORITY_CHANGED, $old, $priority);

        return $ticket->fresh($this->detailRelations());
    }

    /**
     * Parish self-resolve: mark resolved and auto-close in one step.
     */
    public function resolveByTenant(
        SupportTicket $ticket,
        User $actor,
        ?string $summary = null,
    ): SupportTicket {
        return DB::transaction(function () use ($ticket, $actor, $summary): SupportTicket {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            $locked->resolution_category = TenantResolutionCategory::forStatus($locked->status);
            $locked->resolution_summary = $summary !== null && $summary !== '' ? $summary : null;
            $locked->save();

            $resolved = $this->transition($locked, $actor, TicketStatus::RESOLVED, $summary);

            if ($resolved->resolved_by_actor === ResolvedByActor::TENANT) {
                return $this->transition($resolved, $actor, TicketStatus::CLOSED);
            }

            return $resolved;
        });
    }

    public function transition(
        SupportTicket $ticket,
        User $actor,
        string $toStatus,
        ?string $reason = null,
    ): SupportTicket {
        return DB::transaction(function () use ($ticket, $actor, $toStatus, $reason): SupportTicket {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            $this->statusMachine->assertCanTransition($from, $toStatus, $reason);

            if ($toStatus === TicketStatus::AWAITING_YOU) {
                $this->sla->pause($locked);
            }

            if ($from === TicketStatus::AWAITING_YOU && $toStatus === TicketStatus::IN_PROGRESS) {
                $this->sla->resume($locked);
            }

            if ($toStatus === TicketStatus::RESOLVED) {
                $locked->resolved_at = now();
                $locked->resolved_by_user_id = $actor->id;
                $locked->resolved_by_actor = $this->actorHasOpsView($actor)
                    ? ResolvedByActor::EKKLESIA
                    : ResolvedByActor::TENANT;
                $locked->reopen_allowed_until = now()->addDays((int) config('supporttickets.reopen_window_days', 15));
            }

            if (in_array($from, [TicketStatus::RESOLVED, TicketStatus::CLOSED], true) && $toStatus === TicketStatus::IN_PROGRESS) {
                if ($locked->reopen_allowed_until && now()->isAfter($locked->reopen_allowed_until)) {
                    throw new SupportTicketException('The reopen window has expired. Please create a new ticket.', 422);
                }
                $locked->resolved_at = null;
                $locked->resolved_by_user_id = null;
                $locked->resolved_by_actor = null;
                $this->events->record($locked, (int) $actor->id, TicketEventType::REOPENED, $from, $toStatus, $reason);
            }

            $locked->status = $toStatus;
            $locked->save();

            $eventType = match ($toStatus) {
                TicketStatus::CANCELLED => TicketEventType::CANCELLED,
                TicketStatus::CLOSED => TicketEventType::CLOSED,
                TicketStatus::RESOLVED => TicketEventType::RESOLUTION,
                default => TicketEventType::STATUS_CHANGED,
            };

            $this->events->record($locked, (int) $actor->id, $eventType, $from, $toStatus, $reason);

            return $locked->fresh($this->detailRelations());
        });
    }

    public function actorHasOpsView(User $user): bool
    {
        return method_exists($user, 'hasPermission') && $user->hasPermission('support.ops.tickets.view');
    }

    /**
     * @param  Builder<SupportTicket>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyTenantVisibility(Builder $query, User $actor, bool $canViewAll, array $filters): void
    {
        $scope = (string) ($filters['scope'] ?? 'mine');

        if ($scope === 'all') {
            if (! $canViewAll) {
                throw new SupportTicketException('You do not have permission to view all parish tickets.', 403);
            }

            return;
        }

        $userId = (int) $actor->id;
        $query->where(function (Builder $q) use ($userId): void {
            $q->where('requester_user_id', $userId)
                ->orWhereHas('participants', fn (Builder $p) => $p->where('user_id', $userId));
        });
    }

    /**
     * @param  Builder<SupportTicket>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyListFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['q'])) {
            $term = '%'.addcslashes((string) $filters['q'], '%_').'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('subject', 'ilike', $term)
                    ->orWhere('ticket_number', 'ilike', $term);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['request_type_id'])) {
            $query->where('request_type_id', (int) $filters['request_type_id']);
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (! empty($filters['affected_module'])) {
            $query->where('affected_module', $filters['affected_module']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $sort = (string) ($filters['sort'] ?? 'updated_at');
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        if (in_array($sort, ['updated_at', 'created_at', 'priority', 'status', 'ticket_number'], true)) {
            $query->orderBy($sort, $direction);
        }
    }

    /**
     * @param  Builder<SupportTicket>  $query
     */
    private function resolveIdentifier(Builder $query, string $identifier): ?SupportTicket
    {
        if (preg_match('/^ES-\d+$/i', $identifier)) {
            return $query->byTicketNumber($identifier)->first();
        }

        if (ctype_digit($identifier)) {
            return $query->whereKey((int) $identifier)->first();
        }

        return null;
    }

    private function resolveQueueId(?int $queueId, string $requestTypeSlug): ?int
    {
        if ($queueId) {
            return SupportQueue::query()->whereKey($queueId)->value('id');
        }

        $map = [
            'billing_subscription' => 'billing',
            'account_user_access' => 'account_access',
            'security_concern' => 'security',
            'data_issue' => 'data_support',
            'bug_report' => 'product_support',
            'feature_request' => 'product_support',
        ];

        $slug = $map[$requestTypeSlug] ?? 'application_support';

        return SupportQueue::query()->where('slug', $slug)->value('id');
    }

    private function baseListQuery(int $tenantId): Builder
    {
        return SupportTicket::query()
            ->forTenant($tenantId)
            ->with($this->listRelations());
    }

    /** @return list<string> */
    private function listRelations(): array
    {
        return [
            'requestType:id,slug,name',
            'category:id,name',
            'queue:id,name',
            'requester:id,name',
        ];
    }

    /** @return list<string> */
    private function detailRelations(): array
    {
        return array_merge($this->listRelations(), [
            'publicComments.author:id,name',
            'attachments',
            'participants.user:id,name',
            'events.actor:id,name',
            'resolvedBy:id,name',
        ]);
    }

    private function plainText(string $value): string
    {
        return trim(strip_tags($value));
    }

    private function assertPlatformAgent(int $userId): void
    {
        $agent = User::query()->whereKey($userId)->where('active', 1)->first();

        if (! $agent || ! $this->actorHasOpsView($agent)) {
            throw new SupportTicketException('Invalid support agent.', 422);
        }
    }

    private function scheduleSlaJobs(SupportTicket $ticket): void
    {
        $warningMinutes = (int) config('supporttickets.sla_warning_minutes_before', 60);

        if ($ticket->first_response_due_at) {
            $warningAt = $ticket->first_response_due_at->copy()->subMinutes($warningMinutes);
            if ($warningAt->isFuture()) {
                MarkSlaWarningJob::dispatch((int) $ticket->tenant_id, (int) $ticket->id)
                    ->delay($warningAt);
            }
            if ($ticket->first_response_due_at->isFuture()) {
                MarkSlaBreachJob::dispatch((int) $ticket->tenant_id, (int) $ticket->id)
                    ->delay($ticket->first_response_due_at);
            }
        }

        if ($ticket->resolution_due_at && $ticket->resolution_due_at->isFuture()) {
            MarkSlaBreachJob::dispatch((int) $ticket->tenant_id, (int) $ticket->id)
                ->delay($ticket->resolution_due_at);
        }
    }
}
