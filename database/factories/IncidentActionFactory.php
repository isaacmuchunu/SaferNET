<?php

namespace Database\Factories;

use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentAction>
 */
class IncidentActionFactory extends Factory
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
            'incident_id' => Incident::factory(),
            'actor_id' => User::factory()->cde(),
            'action' => 'acknowledged',
            'notes' => fake()->sentence(),
        ];
    }
}
