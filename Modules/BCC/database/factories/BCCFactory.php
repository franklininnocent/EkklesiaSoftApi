<?php

namespace Modules\BCC\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\BCC\Models\BCC;
use Modules\Tenants\Models\Tenant;

class BCCFactory extends Factory
{
    protected $model = BCC::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->unique()->company() . ' BCC',
            'description' => $this->faker->optional()->sentence(),
            'meeting_place' => $this->faker->optional()->address(),
            'meeting_day' => $this->faker->randomElement(['monday','tuesday','wednesday','thursday','friday','saturday','sunday']),
            'meeting_time' => $this->faker->time('H:i'),
            'meeting_frequency' => $this->faker->randomElement(['Weekly','Bi-weekly','Monthly','Quarterly']),
            'status' => $this->faker->randomElement(['active','inactive','suspended']),
            'established_date' => $this->faker->optional()->date(),
            'notes' => $this->faker->optional()->sentence(),
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [ 'status' => 'active' ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [ 'status' => 'inactive' ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [ 'status' => 'suspended' ]);
    }
}

