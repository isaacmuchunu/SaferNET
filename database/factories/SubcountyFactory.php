<?php

namespace Database\Factories;

use App\Models\Subcounty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subcounty>
 */
class SubcountyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().' Subcounty',
            'code' => fake()->unique()->bothify('SC-###'),
            'is_active' => true,
        ];
    }
}
