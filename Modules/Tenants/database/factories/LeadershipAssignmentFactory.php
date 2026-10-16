<?php

namespace Modules\Tenants\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Family\Models\Person;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\LeadershipAssignmentStatus;

class LeadershipAssignmentFactory extends Factory
{
    protected $model = LeadershipAssignment::class;

    public function definition(): array
    {
        $start = now()->subMonths(3)->toDateString();

        return [
            'tenant_id' => Tenant::factory(),
            'church_profile_id' => function (array $attributes) {
                $tenantId = $attributes['tenant_id'] instanceof Tenant
                    ? $attributes['tenant_id']->id
                    : $attributes['tenant_id'];

                return ChurchProfile::query()->firstOrCreate(
                    ['tenant_id' => $tenantId],
                    ['country' => 'Test Country']
                )->id;
            },
            'person_id' => Person::factory(),
            'role_id' => LeadershipRole::factory()->pastor(),
            'jurisdiction_name' => null,
            'appointment_date' => $start,
            'start_date' => $start,
            'end_date' => null,
            'status' => LeadershipAssignmentStatus::ACTIVE,
            'appointment_letter_ref' => null,
            'exit_reason_code' => null,
            'exit_reason_note' => null,
            'legacy_church_leadership_id' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => LeadershipAssignmentStatus::ACTIVE,
            'end_date' => null,
        ]);
    }

    public function completed(?string $endDate = null): static
    {
        return $this->state(fn () => [
            'status' => LeadershipAssignmentStatus::COMPLETED,
            'end_date' => $endDate ?? now()->subDay()->toDateString(),
            'exit_reason_code' => 'completed',
        ]);
    }
}
