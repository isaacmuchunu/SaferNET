<?php

namespace Tests\Feature\Api\V1;

use App\Models\Device;
use App\Models\Institution;
use App\Models\Learner;
use App\Models\LearnerSession;
use App\Models\ProtectionComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Health must describe enforcement, not contact. These tests pin the three ways
 * the two used to be confused: a heartbeat asserting a policy was installed, a
 * device that stopped reporting still counting as protected, and an empty
 * estate reading as a healthy one.
 */
class DeploymentHealthTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_heartbeat_without_a_successful_sync_is_not_healthy(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs(User::factory()->service($institution)->create(), ['telemetry:write']);

        $this->postJson(route('api.v1.extension.heartbeat'), [
            'workstation_id' => 'LAB-A-07',
            'last_error' => 'SaferNET API returned HTTP 500.',
        ])->assertOk()->assertJsonPath('health_status', 'degraded');

        $component = ProtectionComponent::withoutGlobalScopes()->sole();
        $this->assertSame('degraded', $component->health_status);
        $this->assertNull($component->policy_synced_at, 'A heartbeat must not claim a policy was installed.');
        $this->assertNotNull($component->last_seen_at, 'Contact is still recorded.');
    }

    public function test_a_heartbeat_records_the_clients_own_last_successful_sync(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs(User::factory()->service($institution)->create(), ['telemetry:write']);

        $syncedAt = now()->subMinutes(3);

        $this->postJson(route('api.v1.extension.heartbeat'), [
            'workstation_id' => 'LAB-A-07',
            'last_synced_at' => $syncedAt->toIso8601String(),
            'applied_revision' => 4321,
            'policy_complete' => false,
        ])->assertOk()->assertJsonPath('health_status', 'healthy');

        $component = ProtectionComponent::withoutGlobalScopes()->sole();
        $this->assertSame($syncedAt->timestamp, $component->policy_synced_at->timestamp);
        $this->assertSame(4321, $component->metadata['applied_revision']);
        $this->assertFalse($component->metadata['policy_complete']);
    }

    public function test_a_sync_that_has_aged_out_stops_counting_as_healthy(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs(User::factory()->service($institution)->create(), ['telemetry:write']);

        $this->postJson(route('api.v1.extension.heartbeat'), [
            'workstation_id' => 'LAB-A-07',
            'last_synced_at' => now()->subDay()->toIso8601String(),
        ])->assertOk()->assertJsonPath('health_status', 'degraded');
    }

    public function test_a_component_that_stops_checking_in_stops_counting_as_protected(): void
    {
        $institution = Institution::factory()->create();
        $component = ProtectionComponent::factory()->create([
            'institution_id' => $institution->id,
            'health_status' => 'healthy',
            'last_seen_at' => now(),
            'policy_synced_at' => now(),
        ]);

        $this->assertSame('healthy', $component->effectiveHealthStatus());

        $component->update(['last_seen_at' => now()->subMinutes(45)]);
        $this->assertSame('degraded', $component->fresh()->effectiveHealthStatus());

        $component->update(['last_seen_at' => now()->subDay()]);
        $this->assertSame('offline', $component->fresh()->effectiveHealthStatus());
    }

    public function test_the_dashboard_counts_derived_health_not_the_stored_column(): void
    {
        $institution = Institution::factory()->create();
        $officer = User::factory()->hoi($institution)->create();

        ProtectionComponent::factory()->create([
            'institution_id' => $institution->id,
            'health_status' => 'healthy',
            'last_seen_at' => now(),
            'policy_synced_at' => now(),
        ]);

        // Still claims health, but has not been heard from in a day.
        ProtectionComponent::factory()->create([
            'institution_id' => $institution->id,
            'health_status' => 'healthy',
            'last_seen_at' => now()->subDay(),
            'policy_synced_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($officer, ['portal:access']);

        $this->getJson(route('api.v1.dashboard'))
            ->assertOk()
            ->assertJsonPath('data.components_by_health.healthy', 1)
            ->assertJsonPath('data.components_by_health.offline', 1)
            ->assertJsonPath('data.components_total', 2);
    }

    public function test_an_empty_estate_reports_no_components_rather_than_health(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);

        $this->getJson(route('api.v1.dashboard'))
            ->assertOk()
            ->assertJsonPath('data.components_total', 0)
            ->assertJsonPath('data.components_by_health', []);
    }

    public function test_the_scheduled_command_writes_staleness_into_the_stored_column(): void
    {
        $institution = Institution::factory()->create();
        $quiet = ProtectionComponent::factory()->create([
            'institution_id' => $institution->id,
            'health_status' => 'healthy',
            'last_seen_at' => now()->subDay(),
        ]);
        $current = ProtectionComponent::factory()->create([
            'institution_id' => $institution->id,
            'health_status' => 'healthy',
            'last_seen_at' => now(),
            'policy_synced_at' => now(),
        ]);

        $this->artisan('safernet:expire-stale-components')->assertSuccessful();

        $this->assertSame('offline', $quiet->fresh()->health_status);
        $this->assertSame('healthy', $current->fresh()->health_status);
    }

    public function test_a_workstation_resolves_its_own_learner_session(): void
    {
        $institution = Institution::factory()->create();
        $device = Device::factory()->for($institution)->create(['asset_tag' => 'ICTLAB-07']);
        $learner = Learner::factory()->for($institution)->create(['first_name' => 'Amina', 'last_name' => 'Otieno']);
        $session = LearnerSession::create([
            'institution_id' => $institution->id,
            'device_id' => $device->id,
            'learner_id' => $learner->id,
            'identity_source' => 'school_pin',
            'started_at' => now(),
        ]);

        Sanctum::actingAs(User::factory()->service($institution)->create(), ['telemetry:write']);

        $this->getJson(route('api.v1.extension.session', ['workstation_id' => 'ICTLAB-07']))
            ->assertOk()
            ->assertJsonPath('session.id', $session->id)
            ->assertJsonPath('session.learner_name', 'Amina Otieno');

        // A learner change is picked up without anyone editing the extension.
        $session->update(['ended_at' => now(), 'end_reason' => 'sign_out']);

        $this->getJson(route('api.v1.extension.session', ['device_id' => $device->id]))
            ->assertOk()
            ->assertJsonPath('session', null)
            ->assertJsonPath('reason', 'no_open_session');
    }

    public function test_a_session_from_another_school_is_never_resolved(): void
    {
        $institution = Institution::factory()->create();
        $foreignDevice = Device::factory()->create(['asset_tag' => 'OTHER-01']);

        Sanctum::actingAs(User::factory()->service($institution)->create(), ['telemetry:write']);

        $this->getJson(route('api.v1.extension.session', ['device_id' => $foreignDevice->id]))
            ->assertOk()
            ->assertJsonPath('session', null)
            ->assertJsonPath('reason', 'no_device');
    }
}
