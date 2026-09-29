<?php

namespace Modules\Sacraments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Authentication\Models\User;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Support\SacramentStatus;
use Modules\Tenants\Models\Tenant;

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
            'certificate_number' => 'CERT-'.$this->faker->unique()->numerify('####'),
            'book_number' => 'BOOK-'.$this->faker->numerify('##'),
            'page_number' => (string) $this->faker->numberBetween(1, 500),
            'registry_entry' => null,
            'lock_version' => 0,
            'recipient_birth_date' => $this->faker->date(),
            'recipient_birth_place' => $this->faker->city(),
            'father_name' => $this->faker->name('male'),
            'mother_name' => $this->faker->name('female'),
            'godparent1_name' => $this->faker->name(),
            'godparent2_name' => $this->faker->name(),
            'witnesses' => implode(', ', $this->faker->words(3)),
            'notes' => $this->faker->optional()->paragraph(),
            'status' => $this->faker->randomElement([
                SacramentStatus::REGISTERED,
                SacramentStatus::CONDITIONAL,
                SacramentStatus::VOIDED,
            ]),
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }

    public function registered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SacramentStatus::REGISTERED,
            'lock_version' => 0,
        ]);
    }

    /** @deprecated Prefer registered() */
    public function active(): static
    {
        return $this->registered();
    }

    public function voided(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SacramentStatus::VOIDED,
        ]);
    }

    /** @deprecated Prefer voided() */
    public function cancelled(): static
    {
        return $this->voided();
    }

    public function conditional(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SacramentStatus::CONDITIONAL,
            'conditional_date' => $this->faker->date(),
            'conditional_reason' => $this->faker->sentence(),
        ]);
    }
}
