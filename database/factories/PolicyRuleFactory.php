<?php

namespace Database\Factories;

use App\Models\ContentCategory;
use App\Models\FilteringPolicy;
use App\Models\PolicyRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PolicyRule>
 */
class PolicyRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'filtering_policy_id' => FilteringPolicy::factory(),
            'content_category_id' => ContentCategory::factory(),
            'action' => 'block',
            'severity' => 'low',
            'is_locked' => false,
            'counts_toward_incidents' => true,
            'threshold_count' => 5,
            'threshold_window_minutes' => 30,
            'notify_immediately' => false,
        ];
    }
}
