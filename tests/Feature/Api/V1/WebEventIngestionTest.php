<?php

namespace Tests\Feature\Api\V1;

use App\Models\ContentCategory;
use App\Models\Device;
use App\Models\Institution;
use App\Models\Learner;
use App\Models\LearnerSession;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebEventIngestionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_high_severity_top_level_block_creates_incident(): void
    {
        Notification::fake();
        [$user, $session, $category] = $this->attributedSession();
        Sanctum::actingAs($user, ['telemetry:write']);

        $response = $this->postJson(route('api.v1.web-events.store'), $this->payload($session, $category, [
            'request_kind' => 'top_level',
            'severity' => 'high',
        ]));

        $response->assertOk()->assertJsonPath('data.action', 'block')->assertJsonPath('data.incident.severity', 'high');
        $this->assertDatabaseHas('incidents', [
            'learner_id' => $session->learner_id,
            'device_id' => $session->device_id,
            'status' => 'open',
        ]);
    }

    public function test_background_block_does_not_create_incident(): void
    {
        Notification::fake();
        [$user, $session, $category] = $this->attributedSession();
        Sanctum::actingAs($user, ['telemetry:write']);

        $response = $this->postJson(route('api.v1.web-events.store'), $this->payload($session, $category, [
            'request_kind' => 'background',
            'severity' => 'high',
        ]));

        $response->assertOk()->assertJsonMissingPath('data.incident.id');
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_an_event_retried_after_its_session_closed_is_still_attributed(): void
    {
        Notification::fake();
        [$user, $session, $category] = $this->attributedSession();
        Sanctum::actingAs($user, ['telemetry:write']);

        $occurredAt = now()->subMinutes(10);
        $session->update(['ended_at' => now()->subMinutes(5), 'end_reason' => 'sign_out']);

        // A client that lost connectivity delivers this once it is back. The
        // browsing happened while the session was open, so losing it would put
        // a hole in the learner's record.
        $this->postJson(route('api.v1.web-events.store'), $this->payload($session, $category, [
            'occurred_at' => $occurredAt->toIso8601String(),
        ]))->assertOk()->assertJsonPath('data.action', 'block');

        $this->assertDatabaseHas('web_events', [
            'learner_session_id' => $session->id,
            'learner_id' => $session->learner_id,
        ]);

        // The session's last activity is not rewound by the late arrival.
        $this->assertTrue($session->fresh()->last_activity_at->greaterThan($occurredAt));
    }

    public function test_an_event_that_happened_after_a_session_closed_is_refused(): void
    {
        Notification::fake();
        [$user, $session, $category] = $this->attributedSession();
        Sanctum::actingAs($user, ['telemetry:write']);

        $session->update(['ended_at' => now()->subMinutes(10), 'end_reason' => 'sign_out']);

        $this->postJson(route('api.v1.web-events.store'), $this->payload($session, $category, [
            'occurred_at' => now()->toIso8601String(),
        ]))->assertUnprocessable()->assertJsonValidationErrors('learner_session_id');
    }

    public function test_a_retried_event_is_recorded_once(): void
    {
        Notification::fake();
        [$user, $session, $category] = $this->attributedSession();
        Sanctum::actingAs($user, ['telemetry:write']);

        // The client reuses the UUID across retries, which is what makes a retry
        // after an ambiguous failure safe.
        $payload = $this->payload($session, $category);

        $first = $this->postJson(route('api.v1.web-events.store'), $payload)->assertOk();
        $second = $this->postJson(route('api.v1.web-events.store'), $payload)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('web_events', 1);
    }

    /** @return array{User, LearnerSession, ContentCategory} */
    private function attributedSession(): array
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->service($institution)->create();
        $device = Device::factory()->for($institution)->create();
        $learner = Learner::factory()->for($institution)->create();
        $session = LearnerSession::create([
            'institution_id' => $institution->id,
            'device_id' => $device->id,
            'learner_id' => $learner->id,
            'started_by' => $user->id,
            'identity_source' => 'school_pin',
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        return [$user, $session, ContentCategory::factory()->create(['default_severity' => 'high', 'is_high_risk' => true])];
    }

    /** @param array<string, mixed> $overrides */
    private function payload(LearnerSession $session, ContentCategory $category, array $overrides = []): array
    {
        return $overrides + [
            'event_uuid' => (string) Str::uuid(),
            'learner_session_id' => $session->id,
            'content_category_id' => $category->id,
            'url' => 'https://proxy.example/escape',
            'domain' => 'proxy.example',
            'request_kind' => 'top_level',
            'action' => 'block',
            'enforcement_source' => 'extension',
            'severity' => 'high',
            'reason' => 'County proxy and anonymizer rule',
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
