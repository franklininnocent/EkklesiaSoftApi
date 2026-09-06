<?php

namespace Modules\Authentication\Services;

use App\Support\CaseInsensitiveSearch;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Person;
use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Support\LeadershipRoleCategory;

class UserPersonLinkService
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listLinkableClergy(int $tenantId, ?string $search = null, int $limit = 25): Collection
    {
        $linkedPersonIds = User::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('person_id')
            ->pluck('person_id');

        $query = LeadershipAssignment::query()
            ->forTenant($tenantId)
            ->active()
            ->whereNotIn('person_id', $linkedPersonIds)
            ->whereHas('role', fn ($roleQuery) => $roleQuery->where('category', LeadershipRoleCategory::PARISH_CLERGY))
            ->with(['person' => fn ($q) => $q->withTrashed(), 'role'])
            ->orderByDesc('start_date');

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $pattern = '%'.$term.'%';
            $query->whereHas('person', function ($personQuery) use ($pattern, $tenantId): void {
                $personQuery->where('tenant_id', $tenantId)
                    ->where(function ($nameQuery) use ($pattern): void {
                        CaseInsensitiveSearch::applyColumnLike($nameQuery, 'first_name', $pattern);
                        CaseInsensitiveSearch::applyColumnLike($nameQuery, 'last_name', $pattern, 'or');
                        CaseInsensitiveSearch::applyColumnLike($nameQuery, 'email', $pattern, 'or');
                        CaseInsensitiveSearch::applyMemberFullNameLike($nameQuery, $pattern, 'or');
                    });
            });
        }

        return $query->limit($limit)->get()->map(fn (LeadershipAssignment $assignment) => $this->presentLinkableClergy($assignment));
    }

    public function resolvePersonLink(int $tenantId, ?string $personId, ?int $ignoreUserId = null): ?Person
    {
        if ($personId === null || trim($personId) === '') {
            return null;
        }

        $person = Person::query()
            ->forTenant($tenantId)
            ->whereKey($personId)
            ->first();

        if ($person === null) {
            throw ValidationException::withMessages([
                'person_id' => 'Parish person not found in this parish.',
            ]);
        }

        $this->assertActiveParishClergy($tenantId, $person->id);
        $this->assertPersonAvailable($tenantId, $person->id, $ignoreUserId);

        return $person;
    }

    public function attach(User $user, Person $person): User
    {
        $user->person_id = $person->id;
        $user->save();

        return $user->fresh(['person']);
    }

    public function detach(User $user): User
    {
        $user->person_id = null;
        $user->save();

        return $user->fresh();
    }

    private function assertActiveParishClergy(int $tenantId, string $personId): void
    {
        $hasAssignment = LeadershipAssignment::query()
            ->forTenant($tenantId)
            ->where('person_id', $personId)
            ->active()
            ->whereHas('role', fn ($roleQuery) => $roleQuery->where('category', LeadershipRoleCategory::PARISH_CLERGY))
            ->exists();

        if (! $hasAssignment) {
            throw ValidationException::withMessages([
                'person_id' => 'This person must have an active parish clergy leadership role before linking a login.',
            ]);
        }
    }

    private function assertPersonAvailable(int $tenantId, string $personId, ?int $ignoreUserId = null): void
    {
        $existing = User::query()
            ->where('tenant_id', $tenantId)
            ->where('person_id', $personId)
            ->when($ignoreUserId !== null, fn ($query) => $query->where('id', '!=', $ignoreUserId))
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'person_id' => "This leader already has a login ({$existing->email}).",
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function presentLinkableClergy(LeadershipAssignment $assignment): array
    {
        $person = $assignment->person;
        $role = $assignment->role;

        return [
            'assignment_id' => $assignment->id,
            'person_id' => $assignment->person_id,
            'person_name' => $person?->full_name_display ?? '',
            'first_name' => $person?->first_name,
            'last_name' => $person?->last_name,
            'email' => $person?->email,
            'phone' => $person?->phone,
            'role_id' => $assignment->role_id,
            'role_title' => $role?->title,
            'start_date' => $assignment->start_date?->toDateString(),
        ];
    }
}
