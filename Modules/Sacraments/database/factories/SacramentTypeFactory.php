<?php

namespace Modules\Sacraments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sacraments\Models\SacramentType;

class SacramentTypeFactory extends Factory
{
    protected $model = SacramentType::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['Baptism', 'Confirmation', 'Eucharist']),
            'code' => strtoupper($this->faker->unique()->word()),
            'category' => $this->faker->randomElement(['initiation', 'healing', 'service']),
            'description' => $this->faker->sentence(),
            'theological_significance' => $this->faker->paragraph(),
            'display_order' => $this->faker->numberBetween(1, 10),
            'min_age_years' => $this->faker->numberBetween(0, 18),
            'typical_age_years' => $this->faker->numberBetween(7, 25),
            'repeatable' => $this->faker->boolean(),
            'requires_minister' => true,
            'minister_type' => $this->faker->randomElement(['priest', 'bishop', 'deacon']),
            'active' => true,
        ];
    }
}

