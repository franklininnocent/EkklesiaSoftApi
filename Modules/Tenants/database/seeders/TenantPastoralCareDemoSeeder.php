<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\PastoralCare\Models\PastoralCareRequest;
use Modules\PastoralCare\Support\PastoralCarePriority;
use Modules\PastoralCare\Support\PastoralCareStatus;
use Modules\PastoralCare\Support\PastoralCareType;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\TenantDemoResolver;

/**
 * Pastoral care requests across statuses for Operations / Family 360 demos.
 */
class TenantPastoralCareDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = TenantDemoResolver::resolveTenant();
        if (! $tenant) {
            $this->command?->error('Tenant not found for pastoral care demo.');

            return;
        }

        $tenantId = (int) $tenant->id;
        if (PastoralCareRequest::query()->where('tenant_id', $tenantId)->where('notes', TenantDemoMarkers::MARKER)->exists()) {
            $this->command?->info('Pastoral care demo already present (tenant #'.$tenantId.').');

            return;
        }

        $actor = TenantDemoResolver::resolveActor($tenantId);
        if (! $actor) {
            $this->command?->warn('Skipping pastoral care demo: no parish user.');

            return;
        }

        $family = Family::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('family_code')
            ->first();

        if (! $family) {
            $family = Family::factory()->active()->create([
                'tenant_id' => $tenantId,
                'family_name' => 'Demo Pastoral Care Family',
                'notes' => TenantDemoMarkers::MARKER,
            ]);
        }

        $member = FamilyMember::query()->where('family_id', $family->id)->orderBy('id')->first();
        if (! $member) {
            $member = FamilyMember::factory()->create([
                'family_id' => $family->id,
                'status' => 'active',
            ]);
        }

        $assignee = User::query()
            ->where('tenant_id', $tenantId)
            ->where('id', '!=', $actor->id)
            ->orderBy('id')
            ->first()
            ?? $actor;

        $scenarios = [
            [
                'type' => PastoralCareType::HOME_VISIT,
                'priority' => PastoralCarePriority::ROUTINE,
                'status' => PastoralCareStatus::OPEN,
                'summary' => 'Routine home visit — elderly parent',
                'due_on' => now()->addDays(3)->toDateString(),
            ],
            [
                'type' => PastoralCareType::HOSPITAL_VISIT,
                'priority' => PastoralCarePriority::URGENT,
                'status' => PastoralCareStatus::ASSIGNED,
                'summary' => 'Hospital visit — surgery recovery',
                'due_on' => now()->addDay()->toDateString(),
            ],
            [
                'type' => PastoralCareType::BEREAVEMENT,
                'priority' => PastoralCarePriority::URGENT,
                'status' => PastoralCareStatus::DONE,
                'summary' => 'Bereavement follow-up completed',
                'due_on' => now()->subDays(2)->toDateString(),
            ],
            [
                'type' => PastoralCareType::OTHER,
                'priority' => PastoralCarePriority::ROUTINE,
                'status' => PastoralCareStatus::CANCELLED,
                'summary' => 'Cancelled — family rescheduled',
                'due_on' => now()->addWeek()->toDateString(),
            ],
        ];

        $created = 0;
        foreach ($scenarios as $index => $scenario) {
            $row = PastoralCareRequest::query()->create([
                'tenant_id' => $tenantId,
                'family_id' => $family->id,
                'person_id' => $member->person_id,
                'type' => $scenario['type'],
                'priority' => $scenario['priority'],
                'status' => $scenario['status'],
                'summary' => $scenario['summary'],
                'notes' => TenantDemoMarkers::MARKER,
                'due_on' => $scenario['due_on'],
                'created_by_user_id' => $actor->id,
            ]);

            if ($scenario['status'] === PastoralCareStatus::ASSIGNED) {
                $row->update([
                    'assigned_to_user_id' => $assignee->id,
                    'assigned_by_user_id' => $actor->id,
                    'assigned_at' => now()->subHours(2),
                ]);
            }

            if ($scenario['status'] === PastoralCareStatus::DONE) {
                $row->update([
                    'assigned_to_user_id' => $assignee->id,
                    'assigned_by_user_id' => $actor->id,
                    'assigned_at' => now()->subDays(3),
                    'completed_at' => now()->subDay(),
                ]);
            }

            $created++;
        }

        $this->command?->info(sprintf('Pastoral care demo: %d requests (tenant #%d).', $created, $tenantId));
    }
}
