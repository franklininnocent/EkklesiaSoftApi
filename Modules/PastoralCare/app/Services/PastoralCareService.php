<?php

namespace Modules\PastoralCare\Services;

use App\Support\Html\HtmlSanitizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Family\app\Services\ParishionerFamilyAccessService;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\PastoralCare\Exceptions\PastoralCareException;
use Modules\PastoralCare\Models\PastoralCareRequest;
use Modules\PastoralCare\Support\PastoralCarePriority;
use Modules\PastoralCare\Support\PastoralCareStatus;
use Modules\PastoralCare\Support\PastoralCareType;

class PastoralCareService
{
    public function __construct(
        private readonly ParishionerFamilyAccessService $parishionerAccess,
        private readonly HtmlSanitizer $htmlSanitizer,
    ) {}

    /**
     * @param  array{status?: string, assigned_to_user_id?: int, family_id?: string, mine?: bool}  $filters
     */
    public function paginate(int $tenantId, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = PastoralCareRequest::query()
            ->forTenant($tenantId)
            ->with(['family:id,family_name', 'assignedTo:id,name', 'createdBy:id,name'])
            ->orderByRaw('CASE WHEN priority = ? THEN 0 ELSE 1 END', [PastoralCarePriority::URGENT])
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'assigned' THEN 1 ELSE 2 END")
            ->orderBy('due_on')
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['family_id'])) {
            $query->where('family_id', $filters['family_id']);
        }

        if (! empty($filters['assigned_to_user_id'])) {
            $query->where('assigned_to_user_id', (int) $filters['assigned_to_user_id']);
        }

        return $query->paginate($perPage);
    }

    public function findForTenant(int $tenantId, string $id): PastoralCareRequest
    {
        $request = PastoralCareRequest::query()
            ->forTenant($tenantId)
            ->with(['family:id,family_name', 'assignedTo:id,name', 'createdBy:id,name'])
            ->find($id);

        if (! $request) {
            throw new PastoralCareException('Visit request not found.', 404);
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(int $tenantId, User $actor, array $payload): PastoralCareRequest
    {
        $this->assertStaffActor($actor);

        $family = Family::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $payload['family_id'])
            ->first();

        if (! $family) {
            throw new PastoralCareException('Family not found in this parish.', 404);
        }

        $personId = $payload['person_id'] ?? null;
        if ($personId) {
            $belongs = FamilyMember::query()
                ->where('family_id', $family->id)
                ->where('person_id', $personId)
                ->whereNull('deleted_at')
                ->exists();

            if (! $belongs) {
                throw new PastoralCareException('That person is not in this family.');
            }
        }

        $request = PastoralCareRequest::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'family_id' => $family->id,
            'person_id' => $personId,
            'type' => $payload['type'],
            'priority' => $payload['priority'] ?? PastoralCarePriority::ROUTINE,
            'status' => PastoralCareStatus::OPEN,
            'summary' => $this->plainText((string) $payload['summary'], 255),
            'notes' => isset($payload['notes']) ? $this->plainText((string) $payload['notes'], 2000) : null,
            'due_on' => $payload['due_on'] ?? null,
            'created_by_user_id' => (int) $actor->id,
        ]);

        return $this->findForTenant($tenantId, $request->id);
    }

    public function assign(int $tenantId, User $actor, string $id, int $assigneeId): PastoralCareRequest
    {
        $this->assertStaffActor($actor);
        $request = $this->findForTenant($tenantId, $id);

        if (in_array($request->status, [PastoralCareStatus::DONE, PastoralCareStatus::CANCELLED], true)) {
            throw new PastoralCareException('This visit is already closed.');
        }

        $assignee = $this->eligibleStaffQuery($tenantId)->where('id', $assigneeId)->first();
        if (! $assignee) {
            throw new PastoralCareException('Choose a pastoral staff user from this parish.');
        }

        $request->update([
            'status' => PastoralCareStatus::ASSIGNED,
            'assigned_to_user_id' => $assignee->id,
            'assigned_by_user_id' => (int) $actor->id,
            'assigned_at' => now(),
        ]);

        return $this->findForTenant($tenantId, $request->id);
    }

    public function complete(int $tenantId, User $actor, string $id): PastoralCareRequest
    {
        $this->assertStaffActor($actor);
        $request = $this->findForTenant($tenantId, $id);

        if ($request->status !== PastoralCareStatus::ASSIGNED) {
            throw new PastoralCareException('Assign the visit before marking it done.');
        }

        $canAssign = $actor->hasPermission('pastoral.care.assign');
        $isAssignee = (int) $request->assigned_to_user_id === (int) $actor->id;
        if (! $canAssign && ! $isAssignee) {
            throw new PastoralCareException('Only the assigned staff member can mark this done.', 403);
        }

        $request->update([
            'status' => PastoralCareStatus::DONE,
            'completed_at' => now(),
        ]);

        return $this->findForTenant($tenantId, $request->id);
    }

    public function cancel(int $tenantId, User $actor, string $id): PastoralCareRequest
    {
        $this->assertStaffActor($actor);
        $request = $this->findForTenant($tenantId, $id);

        if (in_array($request->status, [PastoralCareStatus::DONE, PastoralCareStatus::CANCELLED], true)) {
            throw new PastoralCareException('This visit is already closed.');
        }

        $request->update([
            'status' => PastoralCareStatus::CANCELLED,
        ]);

        return $this->findForTenant($tenantId, $request->id);
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    public function staff(int $tenantId): Collection
    {
        return $this->eligibleStaffQuery($tenantId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
            ])
            ->values();
    }

    /**
     * @return array{alerts: list<array<string, mixed>>, tasks: list<array<string, mixed>>}
     */
    public function dashboard(int $tenantId): array
    {
        $active = PastoralCareRequest::query()
            ->forTenant($tenantId)
            ->active()
            ->with(['family:id,family_name', 'assignedTo:id,name'])
            ->orderByRaw('CASE WHEN priority = ? THEN 0 ELSE 1 END', [PastoralCarePriority::URGENT])
            ->orderBy('due_on')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $typeCounts = PastoralCareRequest::query()
            ->forTenant($tenantId)
            ->where('status', PastoralCareStatus::OPEN)
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        $alerts = [];
        foreach (PastoralCareType::all() as $type) {
            $count = (int) ($typeCounts[$type] ?? 0);
            if ($count === 0) {
                continue;
            }
            $alerts[] = [
                'id' => $type,
                'title' => PastoralCareType::label($type).' requests',
                'count' => $count,
                'priority' => $type === PastoralCareType::HOSPITAL_VISIT || $type === PastoralCareType::BEREAVEMENT
                    ? PastoralCarePriority::URGENT
                    : 'normal',
                'action' => 'Review care queue',
            ];
        }

        return [
            'open_count' => PastoralCareRequest::query()->forTenant($tenantId)->where('status', PastoralCareStatus::OPEN)->count(),
            'assigned_count' => PastoralCareRequest::query()->forTenant($tenantId)->where('status', PastoralCareStatus::ASSIGNED)->count(),
            'alerts' => $alerts,
            'tasks' => $active->map(fn (PastoralCareRequest $request) => $this->toArray($request))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(PastoralCareRequest $request): array
    {
        $familyName = $request->family?->family_name ?? 'Family';
        $assigneeName = $request->assignedTo?->name;

        return [
            'id' => $request->id,
            'family_id' => $request->family_id,
            'family_name' => $familyName,
            'person_id' => $request->person_id,
            'type' => $request->type,
            'type_label' => PastoralCareType::label($request->type),
            'priority' => $request->priority,
            'status' => $request->status,
            'summary' => $request->summary,
            'notes' => $request->notes,
            'title' => $request->summary,
            'assignee' => $assigneeName ?: 'Unassigned',
            'assigned_to_user_id' => $request->assigned_to_user_id,
            'due_on' => $request->due_on?->toDateString(),
            'due' => $this->dueLabel($request),
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }

    /**
     * Pastoral leaders and added parish users — not member (parishioner) logins.
     */
    private function eligibleStaffQuery(int $tenantId)
    {
        $ids = User::query()
            ->where('tenant_id', $tenantId)
            ->where('active', 1)
            ->get()
            ->filter(fn (User $user) => ! $this->parishionerAccess->isParishioner($user))
            ->pluck('id')
            ->all();

        return User::query()->whereIn('id', $ids === [] ? [0] : $ids);
    }

    private function assertStaffActor(User $actor): void
    {
        if ($this->parishionerAccess->isParishioner($actor)) {
            throw new PastoralCareException('Parish members cannot create or assign visit requests.', 403);
        }
    }

    private function plainText(string $value, int $max): string
    {
        $clean = $this->htmlSanitizer->sanitize($value) ?? $value;
        $text = trim(html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return Str::limit($text, $max, '');
    }

    private function dueLabel(PastoralCareRequest $request): string
    {
        if (! $request->due_on) {
            return 'No due date';
        }

        $due = $request->due_on->startOfDay();
        $today = now()->startOfDay();
        if ($due->equalTo($today)) {
            return 'Today';
        }
        if ($due->equalTo($today->copy()->addDay())) {
            return 'Tomorrow';
        }

        return $due->toFormattedDateString();
    }
}
