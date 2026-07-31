<?php

namespace Modules\EcclesiasticalData\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Models\DioceseManagement;

/**
 * Factory for creating Bishop test data
 * 
 * @extends Factory<BishopManagement>
 */
class BishopManagementFactory extends Factory
{
    protected $model = BishopManagement::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $givenName = $this->faker->firstName();
        $familyName = $this->faker->lastName();
        
        return [
            'full_name' => $givenName . ' ' . $familyName,
            'given_name' => $givenName,
            'family_name' => $familyName,
            'religious_name' => $this->faker->optional(0.3)->firstName(),
            'archdiocese_id' => DioceseManagement::inRandomOrder()->first()?->id ?? DioceseManagement::factory()->create()->id,
            'ecclesiastical_title_id' => null, // Will be set if ecclesiastical_titles exist
            'appointed_date' => $this->faker->optional(0.9)->date('Y-m-d', '-5 years'),
            'ordained_priest_date' => $this->faker->optional(0.8)->date('Y-m-d', '-15 years'),
            'ordained_bishop_date' => $this->faker->optional(0.8)->date('Y-m-d', '-10 years'),
            'date_of_birth' => $this->faker->optional(0.9)->date('Y-m-d', '-65 years'),
            'email' => $this->faker->optional(0.6)->safeEmail(),
            'phone' => $this->faker->optional(0.5)->phoneNumber(),
            'photo_url' => $this->faker->optional(0.5)->url(),
            'education' => $this->faker->optional(0.6)->sentence(10),
            'status' => 'active',
            'is_current' => true,
            'precedence_order' => 1,
        ];
    }

    /**
     * Create an archbishop specifically
     */
    public function archbishop(): static
    {
        return $this->state(fn (array $attributes) => [
            'additional_titles' => 'Archbishop',
        ]);
    }

    /**
     * Create a bishop specifically
     */
    public function bishop(): static
    {
        return $this->state(fn (array $attributes) => [
            'additional_titles' => 'Bishop',
        ]);
    }

    /**
     * Create an auxiliary bishop
     */
    public function auxiliary(): static
    {
        return $this->state(fn (array $attributes) => [
            'additional_titles' => 'Auxiliary Bishop',
        ]);
    }

    /**
     * Create an emeritus (retired) bishop
     */
    public function emeritus(): static
    {
        return $this->state(fn (array $attributes) => [
            'additional_titles' => 'Emeritus Bishop',
            'status' => 'retired',
            'is_current' => false,
        ]);
    }

    /**
     * Create an inactive bishop
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
            'is_current' => false,
        ]);
    }

    /**
     * Set a specific archdiocese
     */
    public function forArchdiocese(int $archdioceseId): static
    {
        return $this->state(fn (array $attributes) => [
            'archdiocese_id' => $archdioceseId,
        ]);
    }

    /**
     * Create with full contact information
     */
    public function withContact(): static
    {
        return $this->state(fn (array $attributes) => [
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
        ]);
    }

    /**
     * Create with detailed biography
     */
    public function withBiography(): static
    {
        return $this->state(fn (array $attributes) => [
            'biography' => $this->faker->paragraphs(5, true),
            'photo_url' => $this->faker->imageUrl(400, 400, 'people'),
        ]);
    }
}

