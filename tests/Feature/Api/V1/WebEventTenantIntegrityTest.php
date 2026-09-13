<?php

namespace Tests\Feature\Api\V1;

use App\Models\ContentCategory;
use App\Models\Device;
use App\Models\FilteringPolicy;
use App\Models\Institution;
use App\Models\Learner;
use App\Models\LearnerSession;
use App\Models\PolicyRule;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebEventTenantIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_service_identity_cannot_write_to_another_schools_session(): void
    {
        $firstInstitution = Institution::factory()->create();
        $secondInstitution = Institution::factory()->create();
        $session = $this->sessionFor($secondInstitution);
        Sanctum::actingAs(User::factory()->service($firstInstitution)->create(), ['telemetry:write']);

        $this->postJson(route('api.v1.web-events.store'), $this->payload($session))
            ->assertNotFound();

        $this->assertDatabaseCount('web_events', 0);
    }

    public function test_portal_token_cannot_call_machine_telemetry_endpoint(): void
    {
        $institution = Institution::factory()->create();
        $session = $this->sessionFor($institution);
        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);

        $this->postJson(route('api.v1.web-events.store'), $this->payload($session))
            ->assertForbidden();
    }

    public function test_policy_rule_ids_are_derived_and_cross_school_policy_is_rejected(): void
    {
        $institution = Institution::factory()->create();
        $outsideInstitution = Institution::factory()->create();
        $session = $this->sessionFor($institution);
        $category = ContentCategory::factory()->create();
        $outsidePolicy = FilteringPolicy::factory()->create([
            'institution_id' => $outsideInstitution->id,
            'level' => 'institution',
        ]);
        $rule = PolicyRule::factory()->for($outsidePolicy, 'policy')->for($category, 'category')->create();
        Sanctum::actingAs(User::factory()->service($institution)->create(), ['telemetry:write']);

        $this->postJson(route('api.v1.web-events.store'), $this->payload($session, [
            'policy_rule_id' => $rule->id,
            'filtering_policy_id' => $outsidePolicy->id,
            'content_category_id' => $category->id,
            'action' => $rule->action->value,
            'severity' => $rule->severity->value,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['policy_rule_id']);

        $this->assertDatabaseCount('web_events', 0);
    }

    private function sessionFor(Institution $institution): LearnerSession
    {
        $device = Device::factory()->for($institution)->create();
        $learner = Learner::factory()->for($institution)->create();

        return LearnerSession::factory()->create([
            'institution_id' => $institution->id,
            'device_id' => $device->id,
            'learner_id' => $learner->id,
            'started_by' => null,
            'ended_at' => null,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(LearnerSession $session, array $overrides = []): array
    {
        return $overrides + [
            'event_uuid' => (string) Str::uuid(),
            'learner_session_id' => $session->id,
            'url' => 'https://example.test/resource',
            'domain' => 'example.test',
            'request_kind' => 'top_level',
            'action' => 'allow',
            'enforcement_source' => 'gateway',
            'severity' => 'low',
            'reason' => 'Policy decision',
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
