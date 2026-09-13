<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceLearnerAssignment;
use App\Models\Institution;
use App\Models\Learner;
use App\Models\LearnerSession;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sign-in is the one endpoint a learner sits in front of, so it is tested as
 * though the learner is trying to get into someone else's account.
 */
class WorkstationSignInTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('signin-device:LAB-A-07');
    }

    public function test_a_learner_signs_themselves_in_with_their_pin(): void
    {
        [$service, $device, $learner] = $this->workstation();

        $response = $this->postJson(route('api.v1.extension.sign-in'), [
            'workstation_id' => 'LAB-A-07',
            'learner_number' => $learner->learner_number,
            'pin' => '4821',
        ])->assertCreated();

        $response->assertJsonPath('session.learner_id', $learner->id);
        $response->assertJsonPath('device_id', $device->id);

        $session = LearnerSession::withoutGlobalScopes()->sole();
        $this->assertSame($learner->id, $session->learner_id);
        $this->assertSame('school_pin', $session->identity_source);
        $this->assertNull($session->ended_at);
    }

    public function test_signing_in_supersedes_whoever_was_signed_in_before(): void
    {
        [$service, $device, $learner] = $this->workstation();
        $previous = $this->assignedLearner($device, '1111');

        $this->postJson(route('api.v1.extension.sign-in'), [
            'workstation_id' => 'LAB-A-07',
            'learner_number' => $previous->learner_number,
            'pin' => '1111',
        ])->assertCreated();

        $this->postJson(route('api.v1.extension.sign-in'), [
            'workstation_id' => 'LAB-A-07',
            'learner_number' => $learner->learner_number,
            'pin' => '4821',
        ])->assertCreated();

        // The previous session must close, or the next learner's browsing is
        // attributed to the child who walked away.
        $this->assertSame('superseded', LearnerSession::withoutGlobalScopes()
            ->where('learner_id', $previous->id)->sole()->end_reason);
        $this->assertNull(LearnerSession::withoutGlobalScopes()
            ->where('learner_id', $learner->id)->sole()->ended_at);
    }

    public function test_a_wrong_pin_and_an_unknown_learner_are_indistinguishable(): void
    {
        [$service, $device, $learner] = $this->workstation();

        $wrongPin = $this->postJson(route('api.v1.extension.sign-in'), [
            'workstation_id' => 'LAB-A-07',
            'learner_number' => $learner->learner_number,
            'pin' => '0000',
        ])->assertUnprocessable();

        $unknownLearner = $this->postJson(route('api.v1.extension.sign-in'), [
            'workstation_id' => 'LAB-A-07',
            'learner_number' => 'NOT-A-LEARNER',
            'pin' => '4821',
        ])->assertUnprocessable();

        // Identical, so the keyboard cannot be used to discover which learner
        // numbers exist at this school.
        $this->assertSame($wrongPin->json('errors'), $unknownLearner->json('errors'));
    }

    public function test_a_learner_not_assigned_to_the_device_cannot_sign_in_there(): void
    {
        [$service, $device] = $this->workstation();

        // Same school, correct PIN, but this workstation is not theirs.
        $elsewhere = Learner::factory()->for($device->institution)->create([
            'learner_number' => 'OTHER-01',
            'pin_hash' => Hash::make('9999'),
            'status' => 'active',
        ]);

        $this->postJson(route('api.v1.extension.sign-in'), [
            'workstation_id' => 'LAB-A-07',
            'learner_number' => 'OTHER-01',
            'pin' => '9999',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('learner_sessions', 0);
        $this->assertSame('not_assigned', AuditLog::withoutGlobalScopes()
            ->where('event', 'learner_session.sign_in_failed')->sole()->new_values['reason']);
    }

    public function test_a_learner_from_another_school_cannot_sign_in(): void
    {
        [$service, $device] = $this->workstation();

        $foreign = Learner::factory()->create([
            'learner_number' => 'FOREIGN-01',
            'pin_hash' => Hash::make('4821'),
            'status' => 'active',
        ]);

        $this->postJson(route('api.v1.extension.sign-in'), [
            'workstation_id' => 'LAB-A-07',
            'learner_number' => 'FOREIGN-01',
            'pin' => '4821',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('learner_sessions', 0);
        $this->assertNotNull($foreign);
    }

    public function test_pin_guessing_is_locked_out_after_a_handful_of_attempts(): void
    {
        [$service, $device, $learner] = $this->workstation();

        // A four digit PIN falls in minutes without this.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson(route('api.v1.extension.sign-in'), [
                'workstation_id' => 'LAB-A-07',
                'learner_number' => $learner->learner_number,
                'pin' => '000'.$attempt,
            ])->assertUnprocessable();
        }

        $this->postJson(route('api.v1.extension.sign-in'), [
            'workstation_id' => 'LAB-A-07',
            'learner_number' => $learner->learner_number,
            'pin' => '4821',
        ])->assertStatus(429);
    }

    public function test_the_pin_is_never_written_to_the_audit_log(): void
    {
        [$service, $device, $learner] = $this->workstation();

        $this->postJson(route('api.v1.extension.sign-in'), [
            'workstation_id' => 'LAB-A-07',
            'learner_number' => $learner->learner_number,
            'pin' => 'super-secret-pin',
        ])->assertUnprocessable();

        foreach (AuditLog::withoutGlobalScopes()->get() as $log) {
            $this->assertStringNotContainsString('super-secret-pin', json_encode($log->new_values) ?: '');
        }
    }

    public function test_a_learner_can_sign_themselves_out_without_a_pin(): void
    {
        [$service, $device, $learner] = $this->workstation();

        $this->postJson(route('api.v1.extension.sign-in'), [
            'workstation_id' => 'LAB-A-07',
            'learner_number' => $learner->learner_number,
            'pin' => '4821',
        ])->assertCreated();

        // No PIN required: a session left open because someone forgot theirs
        // attributes the next learner's browsing to them.
        $this->postJson(route('api.v1.extension.sign-out'), ['workstation_id' => 'LAB-A-07'])
            ->assertOk()
            ->assertJsonPath('ended', 1);

        $this->assertSame('sign_out', LearnerSession::withoutGlobalScopes()->sole()->end_reason);
    }

    public function test_a_portal_token_cannot_reach_the_workstation_endpoints(): void
    {
        [, $device] = $this->workstation();

        Sanctum::actingAs(User::factory()->hoi($device->institution)->create(), ['portal:access']);

        $this->postJson(route('api.v1.extension.sign-out'), ['workstation_id' => 'LAB-A-07'])->assertForbidden();
    }

    /** @return array{User, Device, Learner} */
    private function workstation(): array
    {
        $institution = Institution::factory()->create();
        $device = Device::factory()->for($institution)->create(['asset_tag' => 'LAB-A-07']);
        $service = User::factory()->service($institution)->create();

        Sanctum::actingAs($service, ['telemetry:write']);

        return [$service, $device, $this->assignedLearner($device, '4821')];
    }

    private function assignedLearner(Device $device, string $pin): Learner
    {
        $learner = Learner::factory()->for($device->institution)->create([
            'pin_hash' => Hash::make($pin),
            'status' => 'active',
        ]);

        DeviceLearnerAssignment::create([
            'institution_id' => $device->institution_id,
            'device_id' => $device->id,
            'learner_id' => $learner->id,
            'assigned_by' => User::factory()->hoi($device->institution)->create()->id,
            'assigned_at' => now(),
        ]);

        return $learner;
    }
}
