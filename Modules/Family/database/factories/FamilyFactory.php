<?php

namespace Modules\Family\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use App\Models\User;

class FamilyFactory extends Factory
{
    protected $model = Family::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'family_name' => $this->faker->lastName() . ' Family',
            'head_of_family' => $this->faker->name(),
            'address_line_1' => $this->faker->streetAddress(),
            'address_line_2' => $this->faker->optional()->secondaryAddress(),
            'city' => $this->faker->city(),
            'state_id' => null,
            'country_id' => null,
            'postal_code' => $this->faker->postcode(),
            'primary_phone' => $this->faker->phoneNumber(),
            'secondary_phone' => $this->faker->optional()->phoneNumber(),
            'email' => $this->faker->optional()->safeEmail(),
            'bcc_id' => null,
            'status' => $this->faker->randomElement(['active', 'inactive']),
            'notes' => $this->faker->optional()->paragraph(),
            'created_by' => User::factory(),
            'updated_by' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}

