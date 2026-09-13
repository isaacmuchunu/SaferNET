<?php

namespace Database\Factories;

use App\Enums\InstitutionStatus;
use App\Models\Institution;
use App\Models\Subcounty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Institution>
 */
class InstitutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subcounty_id' => Subcounty::factory(),
            'name' => fake()->company().' School',
            'nemis_code' => fake()->unique()->numerify('########'),
            'institution_type' => fake()->randomElement(['primary', 'junior', 'secondary', 'special']),
            'ownership' => fake()->randomElement(['public', 'private']),
            'status' => InstitutionStatus::Approved,
            'physical_location' => fake()->streetAddress(),
            'hoi_name' => fake()->name(),
            'hoi_email' => fake()->safeEmail(),
            'hoi_phone' => fake()->numerify('+2547########'),
            'learner_population' => fake()->numberBetween(100, 1200),
            'computing_devices_count' => fake()->numberBetween(5, 100),
            'laboratories_count' => fake()->numberBetween(1, 5),
            'connectivity_type' => 'fiber',
        ];
    }
}
