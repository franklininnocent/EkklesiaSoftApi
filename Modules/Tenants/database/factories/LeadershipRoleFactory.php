<?php

namespace Modules\Tenants\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\LeadershipRoleCategory;

class LeadershipRoleFactory extends Factory
{
    protected $model = LeadershipRole::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'title' => $this->faker->jobTitle(),
            'category' => LeadershipRoleCategory::OTHER,
            'hierarchical_level' => 4,
            'allows_concurrent' => false,
            'is_canonical_mandate' => false,
            'is_active' => true,
        ];
    }

    public function global(): static
    {
        return $this->state(fn () => ['tenant_id' => null]);
    }

    public function forTenant(Tenant|int $tenant): static
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return $this->state(fn () => ['tenant_id' => $tenantId]);
    }

    public function pastor(): static
    {
        return $this->state(fn () => [
            'title' => 'Pastor',
            'category' => LeadershipRoleCategory::PARISH_CLERGY,
            'hierarchical_level' => 2,
            'allows_concurrent' => false,
        ]);
    }

    public function concurrent(): static
    {
        return $this->state(fn () => ['allows_concurrent' => true]);
    }
}
