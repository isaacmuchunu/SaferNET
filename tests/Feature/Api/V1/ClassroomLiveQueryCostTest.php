<?php

namespace Tests\Feature\Api\V1;

use App\Models\ContentCategory;
use App\Models\Device;
use App\Models\Institution;
use App\Models\Laboratory;
use App\Models\Learner;
use App\Models\LearnerSession;
use App\Models\User;
use App\Models\WebEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The live monitor polls every few seconds for the whole of a lesson, so what
 * it costs to answer must track the number of tiles on screen rather than how
 * long the lesson has been running.
 */
class ClassroomLiveQueryCostTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_the_newest_event_of_each_session_is_read(): void
    {
        [$teacher, $sessions] = $this->lessonInProgress(sessions: 3, eventsEach: 40);
        Sanctum::actingAs($teacher, ['portal:access']);

        $response = null;
        $queries = $this->eventQueries(function () use (&$response): void {
            $response = $this->getJson(route('api.v1.classrooms.live'))->assertOk();
        });

        $this->assertCount(
            1,
            array_filter($queries, fn (string $sql): bool => str_contains($sql, 'DISTINCT ON')),
            'The newest event per session is one query, not one per session.',
        );
        $this->assertCount(3, $response->json('data.tiles'));

        // Each tile shows its own session's newest event, not another's.
        foreach ($sessions as $session) {
            $tile = collect($response->json('data.tiles'))->firstWhere('session_id', $session->id);
            $this->assertSame("https://example.test/session-{$session->id}/page-40", $tile['active_url']);
        }
    }

    public function test_the_query_count_does_not_grow_with_session_history(): void
    {
        // The same classroom, measured early in a lesson and again once it has
        // built up a long history. The number of tiles never changes.
        [$teacher, $sessions] = $this->lessonInProgress(sessions: 2, eventsEach: 5);
        Sanctum::actingAs($teacher, ['portal:access']);

        $short = $this->eventQueries(fn () => $this->getJson(route('api.v1.classrooms.live'))->assertOk());

        $category = ContentCategory::factory()->create();
        foreach ($sessions as $session) {
            foreach (range(1, 60) as $index) {
                $this->event($session, $category, 'allow', now()->subMinutes(61 - $index));
            }
        }

        $long = $this->eventQueries(fn () => $this->getJson(route('api.v1.classrooms.live'))->assertOk());

        // Two: the newest event per session, and the windowed counts. Both are
        // fixed regardless of how much history sits behind them.
        $this->assertCount(2, $short);
        $this->assertSame($short, $long, 'A longer lesson must not cost more queries to display.');
    }

    public function test_the_focus_score_is_counted_over_a_recent_window_not_the_whole_session(): void
    {
        config(['classroom.focus_window_minutes' => 60]);

        [$teacher, $sessions] = $this->lessonInProgress(sessions: 1, eventsEach: 0);
        $session = $sessions->first();
        $category = ContentCategory::factory()->create();

        // Blocked earlier in the day, entirely on task for the past hour.
        foreach (range(1, 9) as $index) {
            $this->event($session, $category, 'block', now()->subHours(4));
        }
        $this->event($session, $category, 'allow', now()->subMinutes(5));

        Sanctum::actingAs($teacher, ['portal:access']);

        $tile = $this->getJson(route('api.v1.classrooms.live'))->assertOk()->json('data.tiles.0');

        // Counted over the whole session this would be 10%; over the window the
        // learner is on task, which is what a live monitor should show.
        $this->assertSame(100, $tile['focus_score']);
    }

    /**
     * The web_events queries one request makes. Scoped to that table on
     * purpose: tenancy resolution costs a lookup on the first request of a
     * session and would otherwise mask the property under test.
     *
     * @return list<string>
     */
    private function eventQueries(callable $action): array
    {
        $seen = [];
        DB::listen(function ($query) use (&$seen): void {
            if (str_contains($query->sql, 'web_events')) {
                $seen[] = $query->sql;
            }
        });

        $action();

        return $seen;
    }

    /** @return array{User, Collection<int, LearnerSession>} */
    private function lessonInProgress(int $sessions, int $eventsEach): array
    {
        $institution = Institution::factory()->create();
        $laboratory = Laboratory::factory()->create(['institution_id' => $institution->id]);
        $teacher = User::factory()->clm($institution)->create();
        $category = ContentCategory::factory()->create();

        $created = collect(range(1, $sessions))->map(function () use ($institution, $laboratory, $eventsEach, $category): LearnerSession {
            $device = Device::factory()->for($institution)->create(['laboratory_id' => $laboratory->id]);
            $learner = Learner::factory()->for($institution)->create();

            $session = LearnerSession::create([
                'institution_id' => $institution->id,
                'device_id' => $device->id,
                'learner_id' => $learner->id,
                'identity_source' => 'school_pin',
                'started_at' => now()->subHour(),
                'last_activity_at' => now(),
            ]);

            foreach (range(1, $eventsEach) as $index) {
                $this->event(
                    $session,
                    $category,
                    'allow',
                    now()->subMinutes($eventsEach - $index + 1),
                    "https://example.test/session-{$session->id}/page-{$index}",
                );
            }

            return $session;
        });

        return [$teacher, $created];
    }

    private function event(LearnerSession $session, ContentCategory $category, string $action, $occurredAt, ?string $url = null): WebEvent
    {
        return WebEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'institution_id' => $session->institution_id,
            'learner_session_id' => $session->id,
            'learner_id' => $session->learner_id,
            'device_id' => $session->device_id,
            'content_category_id' => $category->id,
            'url' => $url ?? 'https://example.test/page',
            'domain' => 'example.test',
            'request_kind' => 'top_level',
            'action' => $action,
            'enforcement_source' => 'extension',
            'severity' => 'low',
            'reason' => 'Test fixture',
            'occurred_at' => $occurredAt,
        ]);
    }
}
