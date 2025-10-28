<?php

namespace Modules\Sacraments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Tenants\Models\Tenant;
use App\Models\User;

class SacramentFactory extends Factory
{
    protected $model = Sacrament::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'sacrament_type_id' => SacramentType::factory(),
            'recipient_name' => $this->faker->name(),
            'date_administered' => $this->faker->date(),
            'place_administered' => $this->faker->city(),
            'minister_name' => $this->faker->name('male'),
            'minister_title' => $this->faker->randomElement(['Fr.', 'Msgr.', 'Bishop']),
            'certificate_number' => 'CERT-' . $this->faker->unique()->numerify('####'),
            'book_number' => 'BOOK-' . $this->faker->numerify('##'),
            'page_number' => $this->faker->numberBetween(1, 500),
            'recipient_birth_date' => $this->faker->date(),
            'recipient_birth_place' => $this->faker->city(),
            'father_name' => $this->faker->name('male'),
            'mother_name' => $this->faker->name('female'),
            'godparent1_name' => $this->faker->name(),
            'godparent2_name' => $this->faker->name(),
            'witnesses' => implode(', ', $this->faker->words(3)),
            'notes' => $this->faker->optional()->paragraph(),
            'status' => $this->faker->randomElement(['active', 'cancelled', 'conditional']),
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }

    public function conditional(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'conditional',
            'conditional_date' => $this->faker->date(),
            'conditional_reason' => $this->faker->sentence(),
        ]);
    }
}

