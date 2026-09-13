<?php

namespace Tests\Feature\Api\V1;

use App\Models\Institution;
use App\Models\Laboratory;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Focus mode is explicit state with a target, a revision and an expiry. These
 * tests pin the ways it used to disagree with itself: a lock with nothing to
 * confine learners to, a lock inferred from a command that had since expired,
 * and one command evicting another from a single shared slot.
 */
class ClassroomFocusTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_locking_requires_a_target_so_a_lock_always_restricts_something(): void
    {
        [$teacher, $laboratory] = $this->classroom();
        Sanctum::actingAs($teacher, ['portal:access']);

        $this->postJson(route('api.v1.classrooms.focus-mode'), [
            'laboratory_id' => $laboratory->id,
            'locked' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('url');
    }

    public function test_a_lock_reaches_the_extension_without_a_preceding_url_push(): void
    {
        [$teacher, $laboratory, $institution] = $this->classroom();
        Sanctum::actingAs($teacher, ['portal:access']);

        $this->postJson(route('api.v1.classrooms.focus-mode'), [
            'laboratory_id' => $laboratory->id,
            'locked' => true,
            'url' => 'https://kicd.ac.ke/lesson',
        ])->assertOk()->assertJsonPath('focus.locked', true);

        Sanctum::actingAs(User::factory()->service($institution)->create(), ['telemetry:write']);

        $this->getJson(route('api.v1.extension.commands', ['laboratory_id' => $laboratory->id]))
            ->assertOk()
            ->assertJsonPath('focus.locked', true)
            ->assertJsonPath('focus.url', 'https://kicd.ac.ke/lesson')
            ->assertJsonPath('focus_locked', true);
    }

    public function test_unlocking_is_reported_rather_than_left_to_a_cache_miss(): void
    {
        [$teacher, $laboratory, $institution] = $this->classroom();
        Sanctum::actingAs($teacher, ['portal:access']);

        $this->postJson(route('api.v1.classrooms.focus-mode'), [
            'laboratory_id' => $laboratory->id,
            'locked' => true,
            'url' => 'https://kicd.ac.ke/lesson',
        ])->assertOk();

        $released = $this->postJson(route('api.v1.classrooms.focus-mode'), [
            'laboratory_id' => $laboratory->id,
            'locked' => false,
        ])->assertOk();

        // The revision advances, so a client can tell a release from silence.
        $this->assertSame(2, $released->json('focus.revision'));

        Sanctum::actingAs(User::factory()->service($institution)->create(), ['telemetry:write']);

        $this->getJson(route('api.v1.extension.commands', ['laboratory_id' => $laboratory->id]))
            ->assertOk()
            ->assertJsonPath('focus.locked', false)
            ->assertJsonPath('focus.url', null);
    }

    public function test_an_expired_lock_is_not_honoured_as_a_lock(): void
    {
        [$teacher, $laboratory, $institution] = $this->classroom();
        Sanctum::actingAs($teacher, ['portal:access']);

        $this->postJson(route('api.v1.classrooms.focus-mode'), [
            'laboratory_id' => $laboratory->id,
            'locked' => true,
            'url' => 'https://kicd.ac.ke/lesson',
            'minutes' => 5,
        ])->assertOk()->assertJsonPath('focus.locked', true);

        $this->travel(10)->minutes();

        Sanctum::actingAs(User::factory()->service($institution)->create(), ['telemetry:write']);

        $this->getJson(route('api.v1.extension.commands', ['laboratory_id' => $laboratory->id]))
            ->assertOk()
            ->assertJsonPath('focus.locked', false);
    }

    public function test_a_nudge_does_not_evict_a_lesson_url_the_client_has_not_collected(): void
    {
        [$teacher, $laboratory, $institution] = $this->classroom();
        Sanctum::actingAs($teacher, ['portal:access']);

        $this->postJson(route('api.v1.classrooms.push-url'), [
            'url' => 'https://en.wikipedia.org/wiki/Kenya',
            'laboratory_id' => $laboratory->id,
        ])->assertOk();

        $this->postJson(route('api.v1.classrooms.nudge'), [
            'laboratory_id' => $laboratory->id,
            'message' => 'Eyes on the board please.',
        ])->assertOk();

        Sanctum::actingAs(User::factory()->service($institution)->create(), ['telemetry:write']);

        $response = $this->getJson(route('api.v1.extension.commands', ['laboratory_id' => $laboratory->id]))->assertOk();

        $types = collect($response->json('commands'))->pluck('type')->all();
        $this->assertContains('CLASSROOM_PUSH_URL', $types);
        $this->assertContains('CLASSROOM_ATTENTION_NUDGE', $types);

        $push = collect($response->json('commands'))->firstWhere('type', 'CLASSROOM_PUSH_URL');
        $this->assertSame('https://en.wikipedia.org/wiki/Kenya', $push['url']);
    }

    public function test_a_teacher_cannot_lock_another_schools_laboratory(): void
    {
        [$teacher] = $this->classroom();
        $foreign = Laboratory::factory()->create();
        Sanctum::actingAs($teacher, ['portal:access']);

        $this->postJson(route('api.v1.classrooms.focus-mode'), [
            'laboratory_id' => $foreign->id,
            'locked' => true,
            'url' => 'https://kicd.ac.ke/lesson',
        ])->assertUnprocessable()->assertJsonValidationErrors('laboratory_id');
    }

    /** @return array{User, Laboratory, Institution} */
    private function classroom(): array
    {
        $institution = Institution::factory()->create();
        $laboratory = Laboratory::factory()->create(['institution_id' => $institution->id]);

        return [User::factory()->clm($institution)->create(), $laboratory, $institution];
    }
}
