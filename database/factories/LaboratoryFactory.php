<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\Laboratory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Laboratory>
 */
class LaboratoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'name' => 'Computer Laboratory '.fake()->unique()->numberBetween(1, 9999),
            'location' => fake()->randomElement(['Administration Block', 'Science Wing', 'Library Annex']),
        ];
    }
}
