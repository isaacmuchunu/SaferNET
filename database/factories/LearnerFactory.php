<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Learner>
 */
class LearnerFactory extends Factory
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
            'learner_number' => fake()->unique()->bothify('STU-######'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'pin_hash' => Hash::make('1234'),
            'status' => 'active',
        ];
    }
}
