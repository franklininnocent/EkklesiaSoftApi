<?php

namespace Modules\BCC\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\BCC\Models\BCCLeader;
use Modules\BCC\Models\BCC;
use Modules\Family\Models\FamilyMember;
use App\Models\User;

class BCCLeaderFactory extends Factory
{
    protected $model = BCCLeader::class;

    public function definition(): array
    {
        return [
            'bcc_id' => BCC::factory(),
            'family_member_id' => FamilyMember::factory(),
            'role' => $this->faker->randomElement(['leader', 'coordinator', 'assistant', 'secretary', 'treasurer', 'animator', 'other']),
            'role_description' => $this->faker->optional()->sentence(),
            'appointed_date' => $this->faker->optional()->date(),
            'term_start_date' => $this->faker->optional()->date(),
            'term_end_date' => $this->faker->optional()->date(),
            'is_active' => $this->faker->boolean(80), // 80% chance of being active
            'leader_phone' => $this->faker->optional()->phoneNumber(),
            'leader_email' => $this->faker->optional()->safeEmail(),
            'responsibilities' => $this->faker->optional()->paragraph(),
            'notes' => $this->faker->optional()->paragraph(),
            'created_by' => User::factory(),
            'updated_by' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function leader(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'leader',
            'is_active' => true,
        ]);
    }

    public function coordinator(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'coordinator',
            'is_active' => true,
        ]);
    }
}

