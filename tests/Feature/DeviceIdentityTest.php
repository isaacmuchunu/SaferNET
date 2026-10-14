<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Institution;
use App\Models\Learner;
use App\Models\LearnerGroup;
use App\Models\LearnerSession;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeviceIdentityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_real_device_token_signs_out_only_its_own_workstation(): void
    {
        [$identity, $device, $other] = $this->devices();
        $own = $this->openSession($device);
        $foreign = $this->openSession($other);
        $this->withToken($identity->createToken('workstation', ['telemetry:write'])->plainTextToken);

        $this->postJson(route('api.v1.extension.sign-out'))->assertOk()->assertJsonPath('ended', 1);
        $this->assertNotNull($own->fresh()->ended_at);
        $this->assertNull($foreign->fresh()->ended_at);
    }

    public static function machineDeviceRoutes(): array
    {
        return [
            'sign out' => ['extension.sign-out'],
            'sign in' => ['extension.sign-in'],
            'heartbeat' => ['extension.heartbeat'],
            'security event' => ['security-events.store'],
            'component' => ['protection-components.store'],
        ];
    }

    #[DataProvider('machineDeviceRoutes')]
    public function test_another_device_is_forbidden_even_within_the_same_school(string $route): void
    {
        [$identity, , $other] = $this->devices();
        $session = $this->openSession($other);
        $this->withToken($identity->createToken('workstation', ['telemetry:write'])->plainTextToken);

        $this->postJson(route('api.v1.'.$route), ['device_id' => $other->id])->assertForbidden();
        $this->assertNull($session->fresh()->ended_at);
        $this->assertDatabaseCount('security_events', 0);
        $this->assertDatabaseCount('protection_components', 0);
    }

    public function test_workstation_tags_and_laboratories_cannot_override_the_token(): void
    {
        [$identity, , $other] = $this->devices();
        $this->withToken($identity->createToken('workstation', ['telemetry:write'])->plainTextToken);
        $this->postJson(route('api.v1.extension.sign-out'), ['workstation_id' => $other->asset_tag])->assertForbidden();
        $this->getJson(route('api.v1.extension.commands', ['laboratory_id' => 999]))->assertForbidden();
        $this->getJson(route('api.v1.agent.resolve-device', ['identifier' => $other->asset_tag]))->assertNotFound();
    }

    public function test_session_lookup_and_queued_events_cannot_use_another_devices_session(): void
    {
        [$identity, $device, $other] = $this->devices();
        $own = $this->openSession($device);
        $foreign = $this->openSession($other);
        $this->withToken($identity->createToken('workstation', ['telemetry:write'])->plainTextToken);
        $this->getJson(route('api.v1.extension.session'))->assertOk()->assertJsonPath('session.id', $own->id);
        $this->getJson(route('api.v1.extension.session', ['device_id' => $other->id]))->assertForbidden();
        $this->postJson(route('api.v1.web-events.store'), ['learner_session_id' => $foreign->id])->assertNotFound();
        $this->assertDatabaseCount('web_events', 0);
    }

    public function test_legacy_unbound_tokens_and_tokens_for_deleted_devices_are_refused(): void
    {
        [$identity, $device] = $this->devices();
        $token = $identity->createToken('legacy', ['telemetry:write'])->plainTextToken;
        $identity->update(['managed_device_id' => null]);
        $this->withToken($token)->getJson(route('api.v1.extension.sync'))->assertForbidden();
        $identity->update(['managed_device_id' => $device->id]);
        $device->delete();
        $this->assertNull($identity->fresh()->managed_device_id);
        $this->getJson(route('api.v1.agent.policy'))->assertForbidden();
    }

    public function test_issuing_a_token_requires_a_device_from_the_requested_school(): void
    {
        $institution = Institution::factory()->create();
        $foreign = Device::factory()->create();
        $this->artisan('safernet:issue-service-token', ['institution' => $institution->nemis_code])->assertFailed();
        $this->artisan('safernet:issue-service-token', ['institution' => $institution->nemis_code, '--device' => $foreign->id])->assertFailed();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_retired_devices_token_is_forbidden(): void
    {
        [$identity, $device] = $this->devices();
        $device->update(['status' => 'retired']);
        $this->withToken($identity->createToken('workstation', ['telemetry:write'])->plainTextToken)
            ->getJson(route('api.v1.extension.sync'))->assertForbidden();
    }

    public function test_policy_scope_is_derived_from_the_signed_in_learner_and_cannot_be_selected(): void
    {
        [$identity, $device] = $this->devices();
        $session = $this->openSession($device);
        $group = LearnerGroup::factory()->for($device->institution)->create();
        $other = LearnerGroup::factory()->for($device->institution)->create();
        $session->learner->update(['learner_group_id' => $group->id]);
        $this->withToken($identity->createToken('workstation', ['telemetry:write'])->plainTextToken);
        $this->getJson(route('api.v1.extension.sync'))->assertOk()->assertJsonPath('learner_group_id', $group->id);
        $this->getJson(route('api.v1.agent.policy', ['learner_group_id' => $other->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('learner_group_id');
    }

    private function devices(): array
    {
        $institution = Institution::factory()->create();
        $device = Device::factory()->for($institution)->create(['asset_tag' => 'LAB-A']);
        $other = Device::factory()->for($institution)->create(['asset_tag' => 'LAB-B']);

        return [User::factory()->service($institution, $device)->create(), $device, $other];
    }

    private function openSession(Device $device): LearnerSession
    {
        return LearnerSession::factory()->create([
            'institution_id' => $device->institution_id,
            'device_id' => $device->id,
            'learner_id' => Learner::factory()->for($device->institution)->create()->id,
            'ended_at' => null,
        ]);
    }
}
