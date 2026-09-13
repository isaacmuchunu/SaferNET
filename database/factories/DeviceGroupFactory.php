<?php

namespace Database\Factories;

use App\Models\DeviceGroup;
use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceGroup>
 */
class DeviceGroupFactory extends Factory
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
            'name' => 'Learner Workstations '.fake()->unique()->numberBetween(1, 9999),
            'purpose' => 'learner',
        ];
    }
}
