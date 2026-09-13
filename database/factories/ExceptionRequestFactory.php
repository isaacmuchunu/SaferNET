<?php

namespace Database\Factories;

use App\Models\ExceptionRequest;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExceptionRequest>
 */
class ExceptionRequestFactory extends Factory
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
            'requested_by' => User::factory()->cde(),
            'domain' => fake()->unique()->domainName(),
            'reason' => fake()->sentence(),
            'status' => 'pending',
        ];
    }

    public function reviewed(string $decision = 'approved'): static
    {
        return $this->state(fn (): array => [
            'status' => $decision,
            'reviewed_by' => User::factory()->cde(),
            'reviewed_at' => now(),
        ]);
    }
}
