<?php

namespace Modules\Authentication\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

/**
 * User Factory for testing
 * 
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('password'), // Default test password
            'remember_token' => Str::random(10),
            'user_type' => 1, // Regular user
            'is_primary_admin' => false,
            'active' => true,
            'tenant_id' => null,
        ];
    }

    /**
     * Create a super admin user (system-level admin)
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_type' => null, // NULL = system admin
            'is_primary_admin' => true,
            'tenant_id' => null,
        ]);
    }

    /**
     * Create a tenant admin user
     */
    public function tenantAdmin($tenantId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_type' => 2,
            'is_primary_admin' => true,
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Create a regular tenant user
     */
    public function tenantUser($tenantId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_type' => 1,
            'is_primary_admin' => false,
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Create an inactive user
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }

    /**
     * Create an unverified user
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}

