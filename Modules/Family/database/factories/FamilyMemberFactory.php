<?php

namespace Modules\Family\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Family;
use App\Models\User;

class FamilyMemberFactory extends Factory
{
    protected $model = FamilyMember::class;

    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'first_name' => $this->faker->firstName(),
            'middle_name' => $this->faker->optional()->firstName(),
            'last_name' => $this->faker->lastName(),
            'date_of_birth' => $this->faker->date(),
            'gender' => $this->faker->randomElement(['male', 'female']),
            'relationship_to_head' => $this->faker->randomElement(['self', 'spouse', 'son', 'daughter', 'father', 'mother', 'brother', 'sister', 'grandfather', 'grandmother', 'grandson', 'granddaughter', 'uncle', 'aunt', 'nephew', 'niece', 'cousin', 'other']),
            'marital_status' => $this->faker->randomElement(['single', 'married', 'divorced', 'widowed']),
            'phone' => $this->faker->optional()->phoneNumber(),
            'email' => $this->faker->optional()->safeEmail(),
            'is_primary_contact' => false,
            'occupation' => $this->faker->optional()->jobTitle(),
            'education' => $this->faker->optional()->randomElement(['high_school', 'bachelors', 'masters', 'phd']),
            'status' => $this->faker->randomElement(['active', 'inactive']),
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

    public function head(): static
    {
        return $this->state(fn (array $attributes) => [
            'relationship_to_head' => 'self',
            'is_primary_contact' => true,
        ]);
    }
}

