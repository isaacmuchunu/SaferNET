<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
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
            'asset_tag' => fake()->unique()->bothify('ICTLAB-PC###'),
            'serial_number' => fake()->unique()->bothify('SN########'),
            'hostname' => fake()->unique()->bothify('LAB-PC-###'),
            'platform' => 'windows',
            'usage_type' => 'learner',
            'status' => 'active',
        ];
    }
}
