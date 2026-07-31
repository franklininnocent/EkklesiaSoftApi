<?php

namespace Modules\Tenants\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Tenants\Models\Tenant;
use App\Models\User;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company() . ' Church',
            'slogan' => $this->faker->optional()->sentence(),
            'slug' => $this->faker->unique()->slug(),
            'domain' => $this->faker->optional()->domainName(),
            'plan' => $this->faker->randomElement(['free', 'basic', 'premium', 'enterprise']),
            'max_users' => $this->faker->numberBetween(10, 500),
            'max_storage_mb' => $this->faker->numberBetween(100, 10000),
            'trial_ends_at' => $this->faker->optional()->dateTime(),
            'subscription_ends_at' => $this->faker->optional()->dateTime(),
            'active' => $this->faker->boolean(80) ? 1 : 0,
            'settings' => json_encode([
                'timezone' => $this->faker->timezone(),
                'language' => 'en',
                'currency' => 'USD',
            ]),
            'features' => json_encode(['donations', 'events', 'groups']),
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

