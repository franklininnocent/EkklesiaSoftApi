<?php

namespace Modules\EcclesiasticalData\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\Tenants\Models\Denomination;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\State;

/**
 * Factory for creating Diocese test data
 * 
 * @extends Factory<DioceseManagement>
 */
class DioceseManagementFactory extends Factory
{
    protected $model = DioceseManagement::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $types = ['Diocese', 'Archdiocese', 'Eparchy'];
        $type = $this->faker->randomElement($types);
        
        // Get or use default IDs for reference data
        $denominationId = Denomination::inRandomOrder()->first()?->id ?? 1;
        $countryId = Country::inRandomOrder()->first()?->id ?? 101;
        $stateId = State::where('country_id', $countryId)->inRandomOrder()->first()?->id;
        
        return [
            'name' => $type . ' of ' . $this->faker->city(),
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'denomination_id' => $denominationId,
            'country_id' => $countryId,
            'state_id' => $stateId,
            'headquarters_city' => $this->faker->city(),
            'website' => $this->faker->optional(0.7)->url(),
            'description' => $this->faker->optional(0.8)->sentence(20),
            'active' => 1,
            'parent_archdiocese_id' => null,
        ];
    }

    /**
     * Create an archdiocese specifically
     */
    public function archdiocese(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'Archdiocese of ' . $this->faker->city(),
            ];
        });
    }

    /**
     * Create a diocese specifically
     */
    public function diocese(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'Diocese of ' . $this->faker->city(),
            ];
        });
    }

    /**
     * Create an inactive diocese
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => 0,
        ]);
    }

    /**
     * Set a specific country
     */
    public function forCountry(int $countryId): static
    {
        return $this->state(fn (array $attributes) => [
            'country_id' => $countryId,
        ]);
    }

    /**
     * Set a specific state
     */
    public function forState(int $stateId): static
    {
        return $this->state(fn (array $attributes) => [
            'state_id' => $stateId,
        ]);
    }

    /**
     * Set a specific denomination
     */
    public function forDenomination(int $denominationId): static
    {
        return $this->state(fn (array $attributes) => [
            'denomination_id' => $denominationId,
        ]);
    }

    /**
     * Create with a parent archdiocese (suffragan diocese)
     */
    public function withParent(?int $parentId = null): static
    {
        return $this->state(function (array $attributes) use ($parentId) {
            return [
                'parent_archdiocese_id' => $parentId ?? DioceseManagement::factory()->archdiocese()->create()->id,
                'name' => 'Diocese of ' . $this->faker->city(),
            ];
        });
    }
}

