<?php

namespace Modules\Tenants\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\EcclesiasticalData\Services\Leadership\EcclesiasticalLeadershipService;
use Modules\Family\app\Services\PersonService;
use Modules\Family\Models\Person;
use Modules\Tenants\Exceptions\ChurchLeadershipDomainException;
use Modules\Tenants\Models\ChurchLeadership;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Support\LeadershipAssignmentStatus;
use Modules\Tenants\Support\LeadershipExitReason;
use Modules\Tenants\Support\LeadershipRoleCategory;

class LeadershipDomainService
{
    public function __construct(
        private readonly ChurchAuditService $auditService,
        private readonly PersonService $personService,
        private readonly EcclesiasticalLeadershipService $leadershipService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getCurrentLeadership(int $tenantId): array
    {
        $profile = $this->requireChurchProfile($tenantId);

        $assignments = LeadershipAssignment::query()
            ->forTenant($tenantId)
            ->forChurchProfile($profile->id)
            ->active()
            ->with([
                'person' => fn ($q) => $q->withTrashed()->with(['activeFamilyMember.family']),
                'role',
                'legacyChurchLeadership',
            ])
            ->get()
            ->sortBy([
                fn (LeadershipAssignment $a) => $a->role?->hierarchical_level ?? 99,
                fn (LeadershipAssignment $a) => LeadershipRoleCategory::label($a->role?->category ?? LeadershipRoleCategory::OTHER),
                fn (LeadershipAssignment $a) => $a->role?->title ?? '',
                fn (LeadershipAssignment $a) => $a->start_date?->toDateString() ?? '',
            ])
            ->values();

        $grouped = $assignments
            ->groupBy(fn (LeadershipAssignment $a) => $a->role?->category ?? LeadershipRoleCategory::OTHER)
            ->map(function (Collection $items, string $category) {
                return [
                    'category' => $category,
                    'category_label' => LeadershipRoleCategory::label($category),
                    'hierarchical_level' => $items->first()?->role?->hierarchical_level ?? 4,
                    'assignments' => $items->map(fn (LeadershipAssignment $a) => $this->presentAssignment($a))->values(),
                ];
            })
            ->sortBy('hierarchical_level')
            ->values();

        return [
            'church_profile_id' => $profile->id,
            'active_count' => $assignments->count(),
            'groups' => $grouped,
            'assignments' => $assignments->map(fn (LeadershipAssignment $a) => $this->presentAssignment($a))->values(),
        ];
    }

    public function getHistory(int $tenantId, array $filters, int $perPage): LengthAwarePaginator
    {
        $profile = $this->requireChurchProfile($tenantId);

        $query = LeadershipAssignment::query()
            ->forTenant($tenantId)
            ->forChurchProfile($profile->id)
            ->with([
                'person' => fn ($q) => $q->withTrashed()->with(['activeFamilyMember.family']),
                'role',
                'legacyChurchLeadership',
            ])
            ->orderByDesc('start_date')
            ->orderByDesc('created_at');

        if (! empty($filters['person_id'])) {
            $query->where('person_id', $filters['person_id']);
        }

        if (! empty($filters['role_id'])) {
            $query->where('role_id', $filters['role_id']);
        }

        if (! empty($filters['category'])) {
            $query->whereHas('role', fn ($q) => $q->where('category', $filters['category']));
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['from'])) {
            $query->where('start_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where(function ($q) use ($filters): void {
                $q->whereNull('end_date')->orWhere('end_date', '<=', $filters['to']);
            });
        }

        if (! empty($filters['as_of'])) {
            $asOf = $filters['as_of'];
            $query->where('start_date', '<=', $asOf)
                ->where(function ($q) use ($asOf): void {
                    $q->whereNull('end_date')->orWhere('end_date', '>=', $asOf);
                });
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assignLeader(int $tenantId, array $payload): LeadershipAssignment
    {
        return DB::transaction(function () use ($tenantId, $payload) {
            $profile = $this->requireChurchProfile($tenantId);
            $validated = $this->validateAssignment($tenantId, $profile->id, $payload);

            $role = $validated['role'];
            $person = $validated['person'];

            if (! $role->allows_concurrent) {
                $incumbent = $this->findActiveIncumbent($profile->id, $role->id);
                if ($incumbent !== null) {
                    throw ChurchLeadershipDomainException::conflict(
                        'This role currently has an active incumbent. Use the handover workflow to replace them.',
                        [
                            'incumbent' => $this->presentAssignment($incumbent->load(['person', 'role'])),
                        ]
                    );
                }
            }

            $this->assertNoPersonRoleOverlap(
                $profile->id,
                $person->id,
                $role->id,
                $validated['start_date'],
                $validated['end_date'] ?? null,
                $role->allows_concurrent
            );

            $assignment = LeadershipAssignment::create([
                'tenant_id' => $tenantId,
                'church_profile_id' => $profile->id,
                'person_id' => $person->id,
                'role_id' => $role->id,
                'jurisdiction_name' => $payload['jurisdiction_name'] ?? null,
                'appointment_date' => $validated['appointment_date'] ?? null,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'] ?? null,
                'status' => LeadershipAssignmentStatus::ACTIVE,
                'appointment_letter_ref' => $payload['appointment_letter_ref'] ?? null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $assignment->load(['person', 'role']);

            $this->auditService->log(
                $tenantId,
                'leadership.assigned',
                'leadership_assignment',
                $assignment->id,
                null,
                $this->auditSnapshot($assignment),
                $profile->id,
            );

            $this->leadershipService->invalidateParish((int) $profile->id, $tenantId);

            return $assignment;
        });
    }

    public function uploadAssignmentPhoto(int $tenantId, string $assignmentId, UploadedFile $file): LeadershipAssignment
    {
        $profile = $this->requireChurchProfile($tenantId);

        $assignment = LeadershipAssignment::query()
            ->forTenant($tenantId)
            ->forChurchProfile($profile->id)
            ->whereKey($assignmentId)
            ->first();

        if ($assignment === null) {
            throw ChurchLeadershipDomainException::notFound();
        }

        $this->deleteAssignmentPhotoFile($assignment->photo_url);

        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        if (! in_array($extension, $allowedExtensions, true)) {
            $extension = 'jpg';
        }

        $filename = sprintf(
            'assignment_t%d_%s_%s_%s.%s',
            $tenantId,
            $assignment->id,
            now()->format('YmdHis'),
            Str::random(12),
            $extension,
        );

        $directory = "tenants/{$tenantId}/leadership";
        $storedPath = Storage::disk('public')->putFileAs(
            $directory,
            $file,
            $filename,
            ['visibility' => 'public'],
        );

        if ($storedPath === false) {
            throw new ChurchLeadershipDomainException('Failed to upload leader photo.');
        }

        $assignment->photo_url = $storedPath;
        $assignment->updated_by = Auth::id();
        $assignment->save();

        return $assignment->fresh(['person' => fn ($q) => $q->withTrashed(), 'role', 'legacyChurchLeadership']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{outgoing: LeadershipAssignment, incoming: LeadershipAssignment}
     */
    public function handover(int $tenantId, array $payload): array
    {
        return DB::transaction(function () use ($tenantId, $payload) {
            $outgoing = $this->terminateAssignment(
                $tenantId,
                $payload['outgoing_assignment_id'],
                [
                    'end_date' => $payload['outgoing_end_date'],
                    'exit_reason_code' => $payload['outgoing_exit_reason_code'],
                    'exit_reason_note' => $payload['outgoing_exit_reason_note'] ?? null,
                ]
            );

            $incomingPayload = [
                'is_external' => false,
                'person_id' => $payload['person_id'],
                'role_id' => $payload['role_id'] ?? $outgoing->role_id,
                'appointment_date' => $payload['appointment_date'] ?? null,
                'start_date' => $payload['start_date'],
                'end_date' => $payload['end_date'] ?? null,
                'jurisdiction_name' => $payload['jurisdiction_name'] ?? null,
                'appointment_letter_ref' => $payload['appointment_letter_ref'] ?? null,
            ];

            $incoming = $this->assignLeader($tenantId, $incomingPayload);

            $this->auditService->log(
                $tenantId,
                'leadership.handover',
                'leadership_assignment',
                $incoming->id,
                ['outgoing_assignment_id' => $outgoing->id],
                ['incoming_assignment_id' => $incoming->id],
                $outgoing->church_profile_id,
            );

            return [
                'outgoing' => $outgoing->fresh(['person', 'role']),
                'incoming' => $incoming->fresh(['person', 'role']),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function terminateAssignment(int $tenantId, string $assignmentId, array $payload): LeadershipAssignment
    {
        $profile = $this->requireChurchProfile($tenantId);

        $assignment = LeadershipAssignment::query()
            ->forTenant($tenantId)
            ->forChurchProfile($profile->id)
            ->whereKey($assignmentId)
            ->first();

        if ($assignment === null) {
            throw ChurchLeadershipDomainException::notFound();
        }

        if (! $assignment->isActive()) {
            throw new ChurchLeadershipDomainException('Only an active assignment can be terminated.');
        }

        $endDate = $payload['end_date'];
        if ($endDate < $assignment->start_date->toDateString()) {
            throw new ChurchLeadershipDomainException('End date must be on or after the start date.');
        }

        $exitReason = $payload['exit_reason_code'];
        if (! in_array($exitReason, LeadershipExitReason::all(), true)) {
            throw new ChurchLeadershipDomainException('Invalid exit reason code.');
        }

        $old = $this->auditSnapshot($assignment);

        $assignment->end_date = $endDate;
        $assignment->exit_reason_code = $exitReason;
        $assignment->exit_reason_note = $payload['exit_reason_note'] ?? null;
        $assignment->status = LeadershipExitReason::statusForExitReason($exitReason);
        $assignment->updated_by = Auth::id();
        $assignment->save();

        $assignment->load(['person', 'role']);

        $this->auditService->log(
            $tenantId,
            'leadership.terminated',
            'leadership_assignment',
            $assignment->id,
            $old,
            $this->auditSnapshot($assignment),
            $profile->id,
            ['exit_reason_code' => $exitReason],
        );

        $this->leadershipService->invalidateParish((int) $profile->id, $tenantId);

        return $assignment;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateAssignment(int $tenantId, string $assignmentId, array $payload): LeadershipAssignment
    {
        return DB::transaction(function () use ($tenantId, $assignmentId, $payload) {
            $profile = $this->requireChurchProfile($tenantId);

            $assignment = LeadershipAssignment::query()
                ->forTenant($tenantId)
                ->forChurchProfile($profile->id)
                ->whereKey($assignmentId)
                ->with(['person' => fn ($q) => $q->withTrashed(), 'role'])
                ->first();

            if ($assignment === null) {
                throw ChurchLeadershipDomainException::notFound();
            }

            if (! $assignment->isActive()) {
                throw new ChurchLeadershipDomainException('Only an active assignment can be edited.');
            }

            $role = LeadershipRole::query()
                ->accessibleToTenant($tenantId)
                ->active()
                ->find($payload['role_id']);

            if ($role === null) {
                throw new ChurchLeadershipDomainException('Leadership role not found or inactive.');
            }

            $startDate = (string) $payload['start_date'];
            $appointmentDate = isset($payload['appointment_date']) && $payload['appointment_date'] !== ''
                ? (string) $payload['appointment_date']
                : null;

            if (! $role->allows_concurrent && $assignment->role_id !== $role->id) {
                $incumbent = $this->findActiveIncumbent($profile->id, $role->id, $assignment->id);
                if ($incumbent !== null) {
                    throw ChurchLeadershipDomainException::conflict(
                        'This role currently has an active incumbent. Use the handover workflow to replace them.',
                        [
                            'incumbent' => $this->presentAssignment($incumbent->load(['person', 'role'])),
                        ]
                    );
                }
            }

            $this->assertNoPersonRoleOverlap(
                $profile->id,
                $assignment->person_id,
                $role->id,
                $startDate,
                $assignment->end_date?->toDateString(),
                $role->allows_concurrent,
                $assignment->id,
            );

            $person = $assignment->person;
            if ($person === null) {
                throw ChurchLeadershipDomainException::notFound('Leader person record not found.');
            }

            $old = $this->auditSnapshot($assignment);

            $person->first_name = trim((string) $payload['first_name']);
            $person->last_name = trim((string) $payload['last_name']);
            $person->updated_by = Auth::id();
            $person->save();

            $assignment->role_id = $role->id;
            $assignment->jurisdiction_name = $payload['jurisdiction_name'] ?? null;
            $assignment->appointment_date = $appointmentDate;
            $assignment->start_date = $startDate;
            $assignment->appointment_letter_ref = $payload['appointment_letter_ref'] ?? null;
            $assignment->updated_by = Auth::id();
            $assignment->save();

            $assignment->load(['person' => fn ($q) => $q->withTrashed(), 'role']);

            $this->auditService->log(
                $tenantId,
                'leadership.updated',
                'leadership_assignment',
                $assignment->id,
                $old,
                $this->auditSnapshot($assignment),
                $profile->id,
            );

            return $assignment;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{role: LeadershipRole, person: Person, start_date: string, end_date: string|null, appointment_date: string|null}
     */
    public function validateAssignment(int $tenantId, int $churchProfileId, array $payload): array
    {
        $person = $this->resolvePersonForAssignment($tenantId, $payload);

        $role = LeadershipRole::query()
            ->accessibleToTenant($tenantId)
            ->active()
            ->find($payload['role_id']);

        if ($role === null) {
            throw new ChurchLeadershipDomainException('Leadership role not found or inactive.');
        }

        $startDate = (string) $payload['start_date'];
        $endDate = isset($payload['end_date']) && $payload['end_date'] !== ''
            ? (string) $payload['end_date']
            : null;

        if ($endDate !== null && $endDate < $startDate) {
            throw new ChurchLeadershipDomainException('End date cannot precede start date.');
        }

        $appointmentDate = isset($payload['appointment_date']) && $payload['appointment_date'] !== ''
            ? (string) $payload['appointment_date']
            : null;

        return [
            'role' => $role,
            'person' => $person,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'appointment_date' => $appointmentDate,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolvePersonForAssignment(int $tenantId, array $payload): Person
    {
        $isExternal = filter_var($payload['is_external'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($isExternal) {
            return $this->personService->create([
                'first_name' => trim((string) ($payload['first_name'] ?? '')),
                'last_name' => trim((string) ($payload['last_name'] ?? '')),
            ], $tenantId, Auth::id());
        }

        return $this->personService->resolve((string) $payload['person_id'], $tenantId);
    }

    public function listRoles(int $tenantId, ?string $category = null): Collection
    {
        $query = LeadershipRole::query()
            ->accessibleToTenant($tenantId)
            ->active()
            ->orderBy('hierarchical_level')
            ->orderBy('title');

        if ($category !== null && $category !== '') {
            $query->where('category', $category);
        }

        return $query->get();
    }

    public function requireChurchProfile(int $tenantId): ChurchProfile
    {
        $profile = ChurchProfile::query()->where('tenant_id', $tenantId)->first();

        if ($profile === null) {
            throw ChurchLeadershipDomainException::notFound('Church profile not found for this parish.');
        }

        return $profile;
    }

    private function findActiveIncumbent(
        int $churchProfileId,
        string $roleId,
        ?string $excludeAssignmentId = null,
    ): ?LeadershipAssignment {
        $query = LeadershipAssignment::query()
            ->forChurchProfile($churchProfileId)
            ->where('role_id', $roleId)
            ->active()
            ->with(['person' => fn ($q) => $q->withTrashed(), 'role']);

        if ($excludeAssignmentId !== null) {
            $query->where('id', '!=', $excludeAssignmentId);
        }

        return $query->first();
    }

    private function assertNoPersonRoleOverlap(
        int $churchProfileId,
        string $personId,
        string $roleId,
        string $startDate,
        ?string $endDate,
        bool $allowsConcurrent,
        ?string $excludeAssignmentId = null,
    ): void {
        if ($allowsConcurrent) {
            return;
        }

        $overlapQuery = LeadershipAssignment::query()
            ->forChurchProfile($churchProfileId)
            ->where('person_id', $personId)
            ->where('role_id', $roleId)
            ->where(function ($q) use ($startDate, $endDate): void {
                $q->where(function ($inner) use ($startDate, $endDate): void {
                    $inner->where('start_date', '<=', $endDate ?? $startDate)
                        ->where(function ($dates) use ($startDate): void {
                            $dates->whereNull('end_date')->orWhere('end_date', '>=', $startDate);
                        });
                });
            })
            ->where('status', LeadershipAssignmentStatus::ACTIVE);

        if ($excludeAssignmentId !== null) {
            $overlapQuery->where('id', '!=', $excludeAssignmentId);
        }

        $overlap = $overlapQuery->first();

        if ($overlap !== null) {
            throw ChurchLeadershipDomainException::conflict(
                'This person already has an overlapping active assignment for this role.',
                ['assignment' => $this->presentAssignment($overlap->load(['person', 'role']))]
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function presentAssignment(LeadershipAssignment $assignment): array
    {
        $person = $assignment->person;
        $role = $assignment->role;
        $photoUrl = $this->resolvePersonPhoto($assignment);

        $durationDays = null;
        if ($assignment->start_date !== null) {
            $end = $assignment->end_date ?? now();
            $durationDays = $assignment->start_date->diffInDays($end);
        }

        return [
            'id' => $assignment->id,
            'tenant_id' => $assignment->tenant_id,
            'church_profile_id' => $assignment->church_profile_id,
            'person_id' => $assignment->person_id,
            'person' => $person ? [
                'id' => $person->id,
                'full_name' => $person->full_name_display ?? trim("{$person->first_name} {$person->last_name}"),
                'first_name' => $person->first_name,
                'last_name' => $person->last_name,
                'email' => $person->email,
                'phone' => $person->phone,
                'status' => $person->status,
                'photo_url' => $photoUrl,
                'photo_full_url' => $this->resolvePhotoFullUrl($photoUrl),
            ] : null,
            'role_id' => $assignment->role_id,
            'role' => $role ? [
                'id' => $role->id,
                'title' => $role->title,
                'category' => $role->category,
                'category_label' => LeadershipRoleCategory::label($role->category),
                'hierarchical_level' => $role->hierarchical_level,
                'allows_concurrent' => $role->allows_concurrent,
            ] : null,
            'jurisdiction_name' => $assignment->jurisdiction_name,
            'appointment_date' => $assignment->appointment_date?->toDateString(),
            'start_date' => $assignment->start_date?->toDateString(),
            'end_date' => $assignment->end_date?->toDateString(),
            'status' => $assignment->status,
            'appointment_letter_ref' => $assignment->appointment_letter_ref,
            'exit_reason_code' => $assignment->exit_reason_code,
            'exit_reason_note' => $assignment->exit_reason_note,
            'duration_days' => $durationDays,
            'legacy_church_leadership_id' => $assignment->legacy_church_leadership_id,
            'created_at' => $assignment->created_at?->toIso8601String(),
            'updated_at' => $assignment->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(LeadershipAssignment $assignment): array
    {
        return [
            'person_id' => $assignment->person_id,
            'role_id' => $assignment->role_id,
            'start_date' => $assignment->start_date?->toDateString(),
            'end_date' => $assignment->end_date?->toDateString(),
            'status' => $assignment->status,
            'exit_reason_code' => $assignment->exit_reason_code,
            'appointment_letter_ref' => $assignment->appointment_letter_ref,
        ];
    }

    private function resolvePersonPhoto(LeadershipAssignment $assignment): ?string
    {
        if (! empty($assignment->photo_url)) {
            return $assignment->photo_url;
        }

        if ($assignment->relationLoaded('legacyChurchLeadership') && $assignment->legacyChurchLeadership?->photo_url) {
            return $assignment->legacyChurchLeadership->photo_url;
        }

        if ($assignment->legacy_church_leadership_id) {
            $legacy = ChurchLeadership::query()
                ->whereKey($assignment->legacy_church_leadership_id)
                ->value('photo_url');

            if (is_string($legacy) && $legacy !== '') {
                return $legacy;
            }
        }

        $person = $assignment->person;
        if ($person === null) {
            return null;
        }

        $member = $person->relationLoaded('activeFamilyMember')
            ? $person->activeFamilyMember
            : $person->activeFamilyMember()->with('family')->first();

        if ($member === null || ! in_array(strtolower((string) $member->relationship_to_head), ['self', 'head'], true)) {
            return null;
        }

        $family = $member->relationLoaded('family') ? $member->family : $member->family()->first();
        if ($family === null) {
            return null;
        }

        if (! empty($family->head_profile_image_url)) {
            return $family->head_profile_image_url;
        }

        if (! empty($family->profile_image_url)) {
            return $family->profile_image_url;
        }

        return null;
    }

    private function resolvePhotoFullUrl(?string $photoUrl): ?string
    {
        if ($photoUrl === null || $photoUrl === '') {
            return null;
        }

        if (str_starts_with($photoUrl, 'http://') || str_starts_with($photoUrl, 'https://') || str_starts_with($photoUrl, 'data:')) {
            return $photoUrl;
        }

        return Storage::disk('public')->url($photoUrl);
    }

    private function deleteAssignmentPhotoFile(?string $photoPath): void
    {
        if ($photoPath === null || $photoPath === '') {
            return;
        }

        if (str_starts_with($photoPath, 'http://') || str_starts_with($photoPath, 'https://')) {
            return;
        }

        if (Storage::disk('public')->exists($photoPath)) {
            Storage::disk('public')->delete($photoPath);
        }
    }
}
