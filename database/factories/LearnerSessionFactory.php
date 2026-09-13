<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Institution;
use App\Models\Learner;
use App\Models\LearnerSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LearnerSession>
 */
class LearnerSessionFactory extends Factory
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
            'device_id' => Device::factory(),
            'learner_id' => Learner::factory(),
            'identity_source' => 'school_pin',
            'started_at' => now(),
            'last_activity_at' => now(),
        ];
    }
}
