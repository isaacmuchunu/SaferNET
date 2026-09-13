<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\ProtectionComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProtectionComponent>
 */
class ProtectionComponentFactory extends Factory
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
            'type' => 'endpoint_agent',
            'identifier' => fake()->unique()->bothify('AGENT-####-####'),
            'version' => '1.0.0',
            'health_status' => 'healthy',
            'last_seen_at' => now(),
            'policy_synced_at' => now(),
        ];
    }

    public function degraded(): static
    {
        return $this->state(fn (): array => ['health_status' => 'degraded']);
    }
}
