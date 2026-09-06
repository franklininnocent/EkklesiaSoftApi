<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Family\app\Services\PersonMatchService;
use Modules\Family\Models\Person;
use Modules\Tenants\Models\ChurchLeadership;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Support\LeadershipAssignmentStatus;
use Modules\Tenants\Support\LeadershipRoleCategory;

class MigrateChurchLeadershipCommand extends Command
{
    protected $signature = 'church:migrate-leadership {--dry-run : Report actions without writing}';

    protected $description = 'Migrate legacy church_leadership rows into leadership_assignments';

    public function handle(PersonMatchService $personMatchService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $migrated = 0;
        $skipped = 0;

        $legacyRows = ChurchLeadership::query()->orderBy('id')->get();

        if ($legacyRows->isEmpty()) {
            $this->info('No legacy church_leadership rows found.');

            return self::SUCCESS;
        }

        foreach ($legacyRows as $legacy) {
            $profile = ChurchProfile::query()->where('tenant_id', $legacy->tenant_id)->first();
            if ($profile === null) {
                $this->warn("Skipping legacy #{$legacy->id}: no church profile for tenant {$legacy->tenant_id}");
                $skipped++;

                continue;
            }

            $startDate = $legacy->start_date?->toDateString()
                ?? $legacy->appointed_date?->toDateString()
                ?? $legacy->created_at?->toDateString();

            if ($startDate === null) {
                $this->warn("Skipping legacy #{$legacy->id}: no safe start date");
                $skipped++;

                continue;
            }

            if (LeadershipAssignment::query()->where('legacy_church_leadership_id', $legacy->id)->exists()) {
                $this->line("Legacy #{$legacy->id} already migrated.");

                continue;
            }

            $role = $this->resolveRole($legacy->role, (int) $legacy->tenant_id, $dryRun);
            $person = $this->resolvePerson($legacy, $personMatchService, $dryRun);

            if ($role === null || $person === null) {
                $skipped++;

                continue;
            }

            $endDate = $legacy->end_date?->toDateString()
                ?? $legacy->relieved_date?->toDateString();

            $status = LeadershipAssignmentStatus::ACTIVE;
            if ((int) $legacy->active === 0 || $endDate !== null) {
                $status = LeadershipAssignmentStatus::COMPLETED;
            }

            $payload = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $legacy->tenant_id,
                'church_profile_id' => $profile->id,
                'person_id' => $person->id,
                'role_id' => $role->id,
                'appointment_date' => $legacy->appointed_date?->toDateString(),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $status,
                'legacy_church_leadership_id' => $legacy->id,
                'created_at' => $legacy->created_at,
                'updated_at' => $legacy->updated_at,
            ];

            if ($dryRun) {
                $this->line("Would migrate legacy #{$legacy->id} → person {$person->id}, role {$role->title}");
            } else {
                LeadershipAssignment::query()->create($payload);
                $this->info("Migrated legacy #{$legacy->id}");
            }

            $migrated++;
        }

        if (! $dryRun) {
            $this->backfillSacramentParticipants();
        }

        $this->info("Done. Migrated: {$migrated}, Skipped: {$skipped}");

        return self::SUCCESS;
    }

    private function resolveRole(?string $roleTitle, int $tenantId, bool $dryRun): ?LeadershipRole
    {
        $title = trim((string) $roleTitle);
        if ($title === '') {
            $title = 'Other Leader';
        }

        $existing = LeadershipRole::query()
            ->where('title', $title)
            ->where(function ($q) use ($tenantId): void {
                $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($dryRun) {
            return new LeadershipRole([
                'id' => (string) Str::uuid(),
                'title' => $title,
                'category' => LeadershipRoleCategory::OTHER,
            ]);
        }

        return LeadershipRole::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'title' => $title,
            'category' => $this->guessCategory($title),
            'hierarchical_level' => 4,
            'allows_concurrent' => false,
            'is_canonical_mandate' => false,
            'is_active' => true,
        ]);
    }

    private function resolvePerson(ChurchLeadership $legacy, PersonMatchService $matcher, bool $dryRun): ?Person
    {
        $parts = preg_split('/\s+/', trim($legacy->full_name)) ?: [];
        $firstName = $parts[0] ?? 'Unknown';
        $lastName = count($parts) > 1 ? end($parts) : 'Leader';

        $matches = $matcher->findPossibleMatches((int) $legacy->tenant_id, [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $legacy->email,
            'phone' => $legacy->phone,
        ]);

        if ($matches !== []) {
            $personId = $matches[0]['person_id'] ?? $matches[0]['id'] ?? null;
            if ($personId !== null) {
                return Person::query()->find($personId);
            }
        }

        if ($dryRun) {
            return new Person([
                'id' => (string) Str::uuid(),
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);
        }

        return Person::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $legacy->tenant_id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $legacy->email,
            'phone' => $legacy->phone,
            'status' => 'active',
        ]);
    }

    private function guessCategory(string $title): string
    {
        $lower = strtolower($title);

        if (str_contains($lower, 'pastor') || str_contains($lower, 'priest') || str_contains($lower, 'deacon') || str_contains($lower, 'vicar')) {
            return LeadershipRoleCategory::PARISH_CLERGY;
        }

        if (str_contains($lower, 'council') || str_contains($lower, 'chair')) {
            return LeadershipRoleCategory::PARISH_COUNCIL;
        }

        if (str_contains($lower, 'bishop') || str_contains($lower, 'archbishop')) {
            return LeadershipRoleCategory::CANONICAL_DIOCESAN;
        }

        if (str_contains($lower, 'choir') || str_contains($lower, 'youth') || str_contains($lower, 'ministry')) {
            return LeadershipRoleCategory::MINISTRY_PIOUS;
        }

        return LeadershipRoleCategory::OTHER;
    }

    private function backfillSacramentParticipants(): void
    {
        if (! DB::getSchemaBuilder()->hasColumn('sacrament_participants', 'leadership_assignment_id')) {
            return;
        }

        LeadershipAssignment::query()
            ->whereNotNull('legacy_church_leadership_id')
            ->each(function (LeadershipAssignment $assignment): void {
                DB::table('sacrament_participants')
                    ->where('church_leadership_id', $assignment->legacy_church_leadership_id)
                    ->whereNull('leadership_assignment_id')
                    ->update(['leadership_assignment_id' => $assignment->id]);
            });
    }
}
