<?php

namespace Modules\Family\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;

class FamilyMemberFactory extends Factory
{
    protected $model = FamilyMember::class;

    public function definition(): array
    {
        $first = $this->faker->firstName();
        $middle = $this->faker->optional()->firstName();
        $last = $this->faker->lastName();
        $dob = $this->faker->date();
        $gender = $this->faker->randomElement(['male', 'female']);

        return [
            'family_id' => Family::factory(),
            // Keep Person identity aligned with membership fields for realistic fixtures (ADR-24).
            'person_id' => Person::factory()->state([
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
                'date_of_birth' => $dob,
                'gender' => $gender,
            ]),
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'date_of_birth' => $dob,
            'gender' => $gender,
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

    public function configure(): static
    {
        return $this->afterMaking(function (FamilyMember $member): void {
            if (! $member->tenant_id && $member->family_id) {
                $tenantId = Family::query()
                    ->whereKey($member->family_id)
                    ->value('tenant_id');

                if ($tenantId !== null) {
                    $member->tenant_id = (int) $tenantId;
                }
            }
        })->afterCreating(function (FamilyMember $member) {
            if (! $member->person_id) {
                return;
            }

            $person = Person::query()->find($member->person_id);
            if (! $person) {
                return;
            }

            $tenantId = $member->family?->tenant_id;
            $person->forceFill([
                'tenant_id' => $tenantId ?? $person->tenant_id,
                'first_name' => $member->first_name,
                'middle_name' => $member->middle_name,
                'last_name' => $member->last_name,
                'date_of_birth' => $member->date_of_birth,
                'gender' => $member->gender,
            ])->save();
        });
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

    public function deceased(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'deceased',
            'deceased_date' => now()->subDay()->format('Y-m-d'),
        ]);
    }

    public function migrated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'migrated',
        ]);
    }

    public function head(): static
    {
        return $this->state(fn (array $attributes) => [
            'relationship_to_head' => 'self',
            'is_primary_contact' => true,
        ]);
    }

    public function spouse(): static
    {
        return $this->state(fn (array $attributes) => [
            'relationship_to_head' => 'spouse',
            'marital_status' => 'married',
        ]);
    }

    public function child(): static
    {
        return $this->state(fn (array $attributes) => [
            'relationship_to_head' => 'daughter',
            'marital_status' => 'single',
            'date_of_birth' => now()->subYears(12)->format('Y-m-d'),
        ]);
    }

    public function elder(): static
    {
        return $this->state(fn (array $attributes) => [
            'relationship_to_head' => 'father',
            'date_of_birth' => now()->subYears(72)->format('Y-m-d'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $sacraments
     */
    public function withSacraments(array $sacraments = []): static
    {
        return $this->state(fn (array $attributes) => array_merge([
            'baptism_date' => $sacraments['baptism_date'] ?? null,
            'baptism_place' => $sacraments['baptism_place'] ?? null,
            'first_communion_date' => $sacraments['first_communion_date'] ?? null,
            'first_communion_place' => $sacraments['first_communion_place'] ?? null,
            'confirmation_date' => $sacraments['confirmation_date'] ?? null,
            'confirmation_place' => $sacraments['confirmation_place'] ?? null,
            'marriage_date' => $sacraments['marriage_date'] ?? null,
            'marriage_place' => $sacraments['marriage_place'] ?? null,
        ], $sacraments));
    }
}
