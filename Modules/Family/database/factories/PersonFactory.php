<?php

namespace Modules\Family\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Family\Models\Person;
use Modules\Tenants\Models\Tenant;

class PersonFactory extends Factory
{
    protected $model = Person::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'first_name' => $this->faker->firstName(),
            'middle_name' => $this->faker->optional()->firstName(),
            'last_name' => $this->faker->lastName(),
            'date_of_birth' => $this->faker->optional()->date(),
            'place_of_birth' => $this->faker->optional()->city(),
            'gender' => $this->faker->randomElement(['male', 'female']),
            'father_name' => $this->faker->optional()->name('male'),
            'mother_name' => $this->faker->optional()->name('female'),
            'phone' => null,
            'email' => $this->faker->optional()->safeEmail(),
            'status' => 'active',
            'created_by' => User::factory(),
            'updated_by' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }
}
