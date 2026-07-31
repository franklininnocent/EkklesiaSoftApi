<?php

namespace Modules\Tenants\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Tenants\Models\Country;

/**
 * Country Factory for testing
 * 
 * @extends Factory<Country>
 */
class CountryFactory extends Factory
{
    protected $model = Country::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->country(),
            'code' => strtoupper($this->faker->unique()->lexify('??')),
            'phone_code' => '+' . $this->faker->numberBetween(1, 999),
            'active' => true,
        ];
    }

    /**
     * Indicate that the country is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }
}

