<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceLearnerAssignment;
use App\Models\Learner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceLearnerAssignment>
 */
class DeviceLearnerAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'learner_id' => Learner::factory(),
            'assigned_by' => User::factory()->cde(),
            'assigned_at' => now(),
        ];
    }
}
