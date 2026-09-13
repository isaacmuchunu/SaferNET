<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory()->cde(),
            'institution_id' => Institution::factory(),
            'event' => 'institution.reviewed',
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'SaferNET Officer Portal',
        ];
    }
}
