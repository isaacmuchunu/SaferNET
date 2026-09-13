<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Incident;
use App\Models\Institution;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
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
            'learner_id' => Learner::factory(),
            'device_id' => Device::factory(),
            'type' => 'filtering_violation',
            'severity' => 'high',
            'status' => 'open',
            'event_count' => 1,
            'first_detected_at' => now()->subMinutes(10),
            'last_detected_at' => now(),
        ];
    }

    /**
     * Raise the incident against a learner and device belonging to the given
     * institution, satisfying the composite tenant foreign keys.
     */
    public function forInstitution(Institution $institution): static
    {
        return $this->state(fn (): array => [
            'institution_id' => $institution->id,
            'learner_id' => Learner::factory()->create(['institution_id' => $institution->id])->id,
            'device_id' => Device::factory()->create(['institution_id' => $institution->id])->id,
        ]);
    }
}
