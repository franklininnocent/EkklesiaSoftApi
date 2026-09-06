<?php

namespace Modules\Tenants\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\Tenant;

class ChurchProfileFactory extends Factory
{
    protected $model = ChurchProfile::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'country' => $this->faker->country(),
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->companyEmail(),
        ];
    }
}
