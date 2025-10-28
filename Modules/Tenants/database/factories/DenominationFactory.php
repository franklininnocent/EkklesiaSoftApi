<?php

namespace Modules\Tenants\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Tenants\Models\Denomination;

/**
 * Denomination Factory for testing
 * 
 * @extends Factory<Denomination>
 */
class DenominationFactory extends Factory
{
    protected $model = Denomination::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $denominations = [
            'Catholic',
            'Orthodox',
            'Protestant',
            'Anglican',
            'Lutheran',
            'Methodist',
            'Baptist',
            'Presbyterian',
        ];

        return [
            'name' => $this->faker->unique()->randomElement($denominations) . ' ' . $this->faker->word(),
            'description' => $this->faker->optional()->sentence(),
            'active' => true,
        ];
    }

    /**
     * Indicate that the denomination is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }
}

