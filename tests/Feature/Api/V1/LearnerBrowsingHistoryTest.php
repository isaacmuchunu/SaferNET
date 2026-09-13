<?php

namespace Tests\Feature\Api\V1;

use App\Models\ContentCategory;
use App\Models\Device;
use App\Models\DeviceLearnerAssignment;
use App\Models\Institution;
use App\Models\Laboratory;
use App\Models\Learner;
use App\Models\LearnerSession;
use App\Models\User;
use App\Models\WebEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Browsing history is recorded against a named child, so who can read it back
 * matters as much as whether it is complete.
 */
class LearnerBrowsingHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_an_officer_can_read_one_learners_browsing_history(): void
    {
        [$officer, $session] = $this->lesson();
        $category = ContentCategory::factory()->create(['name' => 'Gambling']);

        $this->event($session, $category, 'allow', 'wikipedia.org', now()->subMinutes(10));
        $this->event($session, $category, 'block', 'bet-example.co.ke', now()->subMinutes(2));

        Sanctum::actingAs($officer, ['portal:access']);

        $response = $this->getJson(route('api.v1.web-events.index', ['learner_id' => $session->learner_id]))->assertOk();

        // Newest first, so the most recent thing a learner did is the first thing read.
        $this->assertSame('bet-example.co.ke', $response->json('data.0.domain'));
        $this->assertSame('block', $response->json('data.0.action'));
        $this->assertSame('Gambling', $response->json('data.0.category'));
        $this->assertSame('wikipedia.org', $response->json('data.1.domain'));
        $this->assertCount(2, $response->json('data'));
    }

    public function test_the_history_can_be_narrowed_to_blocks(): void
    {
        [$officer, $session] = $this->lesson();
        $category = ContentCategory::factory()->create();

        $this->event($session, $category, 'allow', 'wikipedia.org', now()->subMinutes(10));
        $this->event($session, $category, 'block', 'bet-example.co.ke', now()->subMinutes(2));

        Sanctum::actingAs($officer, ['portal:access']);

        $response = $this->getJson(route('api.v1.web-events.index', [
            'learner_id' => $session->learner_id,
            'action' => 'block',
        ]))->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('bet-example.co.ke', $response->json('data.0.domain'));
    }

    public function test_one_school_can_never_read_another_schools_browsing_history(): void
    {
        [$officer] = $this->lesson();
        [, $foreignSession] = $this->lesson();
        $category = ContentCategory::factory()->create();

        $this->event($foreignSession, $category, 'block', 'bet-example.co.ke', now());

        Sanctum::actingAs($officer, ['portal:access']);

        // Asking for another school's learner by id returns nothing rather than
        // their history — the tenant scope decides, not the filter.
        $this->getJson(route('api.v1.web-events.index', ['learner_id' => $foreignSession->learner_id]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_service_token_cannot_read_browsing_history_back(): void
    {
        [$officer, $session] = $this->lesson();
        $category = ContentCategory::factory()->create();
        $this->event($session, $category, 'block', 'bet-example.co.ke', now());

        // A workstation may report activity; it has no business reading the
        // school's learners back out again.
        $service = User::factory()->service($officer->institution)->create();
        Sanctum::actingAs($service, ['telemetry:write']);

        $this->getJson(route('api.v1.web-events.index'))->assertForbidden();
    }

    public function test_the_live_monitor_reports_whether_a_workstation_is_live(): void
    {
        config(['classroom.reporting_within_seconds' => 120]);

        [$officer, $session] = $this->lesson();
        Sanctum::actingAs($officer, ['portal:access']);

        $session->update(['last_activity_at' => now()->subSeconds(30)]);
        $tile = $this->getJson(route('api.v1.classrooms.live'))->assertOk()->json('data.tiles.0');

        $this->assertTrue($tile['is_reporting']);
        $this->assertNotNull($tile['session_started_at']);
        $this->assertGreaterThanOrEqual(0, $tile['live_for_seconds']);

        // Gone quiet: the tile must stop claiming to be live.
        $session->update(['last_activity_at' => now()->subMinutes(10)]);
        $quiet = $this->getJson(route('api.v1.classrooms.live'))->assertOk()->json('data.tiles.0');

        $this->assertFalse($quiet['is_reporting']);
    }

    public function test_a_utc_timestamp_from_a_client_is_stored_in_the_application_timezone(): void
    {
        config(['app.timezone' => 'Africa/Nairobi', 'classroom.reporting_within_seconds' => 120]);

        [$officer, $session] = $this->lesson();
        $service = User::factory()->service($officer->institution)->create();
        Sanctum::actingAs($service, ['telemetry:write']);

        // Exactly what the browser extension sends: new Date().toISOString().
        $sentAt = now()->utc();

        $this->postJson(route('api.v1.web-events.store'), [
            'event_uuid' => (string) Str::uuid(),
            'learner_session_id' => $session->id,
            'url' => 'https://wikipedia.org/wiki/Kenya',
            'domain' => 'wikipedia.org',
            'request_kind' => 'top_level',
            'action' => 'allow',
            'enforcement_source' => 'extension',
            'severity' => 'low',
            'reason' => 'Learner navigation',
            'occurred_at' => $sentAt->toIso8601ZuluString(),
        ])->assertOk();

        // Stored as the same instant, not the same wall clock. Without the
        // conversion this lands three hours in the past, and every window that
        // depends on it — reporting, focus score, incident thresholds — is wrong.
        $event = WebEvent::withoutGlobalScopes()->latest('id')->sole();
        $this->assertLessThanOrEqual(5, abs($event->occurred_at->diffInSeconds(now())));

        // And the workstation therefore reads as live.
        Sanctum::actingAs($officer, ['portal:access']);
        $tile = $this->getJson(route('api.v1.classrooms.live'))->assertOk()->json('data.tiles.0');
        $this->assertTrue($tile['is_reporting']);
    }

    public function test_a_learner_profile_carries_their_devices_and_activity(): void
    {
        [$officer, $session] = $this->lesson();
        $category = ContentCategory::factory()->create();

        DeviceLearnerAssignment::create([
            'institution_id' => $session->institution_id,
            'device_id' => $session->device_id,
            'learner_id' => $session->learner_id,
            'assigned_by' => $officer->id,
            'assigned_at' => now()->subDay(),
        ]);

        $this->event($session, $category, 'allow', 'wikipedia.org', now()->subMinutes(9));
        $this->event($session, $category, 'block', 'bet-example.co.ke', now()->subMinutes(3));

        Sanctum::actingAs($officer, ['portal:access']);

        $profile = $this->getJson(route('api.v1.learners.show', $session->learner_id))->assertOk();

        // The device a learner uses is the thing that makes their browsing
        // attributable, so a profile has to name it.
        $this->assertSame($session->device_id, $profile->json('data.assignments.0.device.id'));
        $this->assertSame(2, $profile->json('data.activity.events'));
        $this->assertSame(1, $profile->json('data.activity.blocked'));
        $this->assertNotNull($profile->json('data.activity.last_seen_at'));
        $this->assertNotEmpty($profile->json('data.sessions'));

        // The browsing itself is not inlined: it is paginated and filtered on
        // its own endpoint, so a profile never loads thousands of rows.
        $this->assertNull($profile->json('data.web_events'));
    }

    public function test_a_learner_profile_from_another_school_is_refused(): void
    {
        [$officer] = $this->lesson();
        [, $foreignSession] = $this->lesson();

        Sanctum::actingAs($officer, ['portal:access']);

        $this->getJson(route('api.v1.learners.show', $foreignSession->learner_id))->assertNotFound();
    }

    /** @return array{User, LearnerSession} */
    private function lesson(): array
    {
        $institution = Institution::factory()->create();
        $laboratory = Laboratory::factory()->create(['institution_id' => $institution->id]);
        $officer = User::factory()->clm($institution)->create();
        $device = Device::factory()->for($institution)->create(['laboratory_id' => $laboratory->id]);
        $learner = Learner::factory()->for($institution)->create();

        $session = LearnerSession::create([
            'institution_id' => $institution->id,
            'device_id' => $device->id,
            'learner_id' => $learner->id,
            'identity_source' => 'school_pin',
            'started_at' => now()->subMinutes(20),
            'last_activity_at' => now(),
        ]);

        return [$officer, $session];
    }

    private function event(LearnerSession $session, ContentCategory $category, string $action, string $domain, $occurredAt): WebEvent
    {
        return WebEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'institution_id' => $session->institution_id,
            'learner_session_id' => $session->id,
            'learner_id' => $session->learner_id,
            'device_id' => $session->device_id,
            'content_category_id' => $category->id,
            'url' => "https://{$domain}/page",
            'domain' => $domain,
            'request_kind' => 'top_level',
            'action' => $action,
            'enforcement_source' => 'extension',
            'severity' => 'low',
            'reason' => 'Test fixture',
            'occurred_at' => $occurredAt,
            'metadata' => ['page_title' => 'A page'],
        ]);
    }
}
