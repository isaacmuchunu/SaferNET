<?php

namespace Database\Factories;

use App\Models\FilteringPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FilteringPolicy>
 */
class FilteringPolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by' => User::factory()->cde(),
            'name' => fake()->words(3, true),
            'level' => 'county',
            'status' => 'active',
            'version' => 1,
            'effective_from' => now(),
        ];
    }
}
