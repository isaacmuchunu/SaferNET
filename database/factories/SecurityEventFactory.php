<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Institution;
use App\Models\SecurityEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SecurityEvent>
 */
class SecurityEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_uuid' => (string) Str::uuid(),
            'institution_id' => Institution::factory(),
            'device_id' => Device::factory(),
            'type' => 'agent_tamper_attempt',
            'severity' => 'high',
            'description' => 'The protection agent service was stopped on the workstation.',
            'occurred_at' => now(),
        ];
    }
}
