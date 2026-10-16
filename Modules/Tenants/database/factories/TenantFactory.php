<?php

namespace Modules\Tenants\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Tenants\Models\Tenant;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company().' Church',
            'slogan' => $this->faker->optional()->sentence(),
            'slug' => $this->faker->unique()->slug(),
            'domain' => $this->faker->boolean(80)
                ? $this->faker->unique()->slug().'-'.$this->faker->uuid().'.test'
                : null,
            'plan' => $this->faker->randomElement(['free', 'starter', 'standard', 'enterprise']),
            'max_users' => $this->faker->numberBetween(10, 500),
            'max_storage_mb' => $this->faker->numberBetween(100, 10000),
            // Keep lifecycle deterministic: any past date makes the tenant read-only.
            'trial_ends_at' => null,
            'subscription_ends_at' => null,
            'active' => 1,
            'settings' => json_encode([
                'timezone' => $this->faker->timezone(),
                'language' => 'en',
                'currency' => 'USD',
            ]),
            'features' => json_encode(['donations', 'events', 'groups', 'ministries_associations']),
            'primary_color' => $this->faker->hexColor(),
            'secondary_color' => $this->faker->hexColor(),
            'created_by' => User::factory(),
            'updated_by' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => 1,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => 0,
        ]);
    }
}
