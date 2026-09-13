<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\LearnerGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LearnerGroup>
 */
class LearnerGroupFactory extends Factory
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
            'name' => 'Grade '.fake()->numberBetween(1, 9).' '.fake()->unique()->bothify('???'),
            'grade_level' => (string) fake()->numberBetween(1, 9),
            'academic_year' => (string) now()->year,
        ];
    }
}
