<?php

namespace Modules\Family\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Models\Tenant;

class FamilyFactory extends Factory
{
    protected $model = Family::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'family_name' => $this->faker->lastName().' Family',
            'head_of_family' => $this->faker->name(),
            'address_line_1' => $this->faker->streetAddress(),
            'address_line_2' => $this->faker->optional()->secondaryAddress(),
            'city' => $this->faker->city(),
            'state_id' => null,
            'country_id' => null,
            'postal_code' => $this->faker->postcode(),
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

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function withAddress(array $attrs = []): static
    {
        return $this->state(fn (array $attributes) => array_merge([
            'address_line_1' => '123 Oak Street',
            'address_line_2' => null,
            'city' => 'Springfield',
            'postal_code' => '62701',
        ], $attrs));
    }

    public function withPrimaryPhone(string $phone): static
    {
        return $this->afterCreating(function (Family $family) use ($phone) {
            FamilyMember::factory()->head()->active()->create([
                'family_id' => $family->id,
                'phone' => $phone,
                'is_primary_contact' => true,
            ]);
        });
    }
}
