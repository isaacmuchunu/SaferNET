<?php

namespace Tests\Feature\Api\V1;

use App\Models\Device;
use App\Models\DeviceLearnerAssignment;
use App\Models\Institution;
use App\Models\Learner;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LearnerSessionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_assigned_learner_with_valid_pin_starts_attributed_session(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->clm($institution)->create();
        $device = Device::factory()->for($institution)->create();
        $learner = Learner::factory()->for($institution)->create();
        DeviceLearnerAssignment::factory()->create([
            'device_id' => $device->id,
            'learner_id' => $learner->id,
            'assigned_by' => $user->id,
        ]);
        Sanctum::actingAs($user, ['portal:access']);

        $response = $this->postJson(route('api.v1.devices.sessions.store', $device), [
            'learner_id' => $learner->id,
            'identity_source' => 'school_pin',
            'pin' => '1234',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.learner.id', $learner->id)
            ->assertJsonPath('data.attribution', 'system_attribution');
        $this->assertDatabaseHas('learner_sessions', [
            'device_id' => $device->id,
            'learner_id' => $learner->id,
            'ended_at' => null,
        ]);
    }

    public function test_returns_422_for_unassigned_learner(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->clm($institution)->create();
        $device = Device::factory()->for($institution)->create();
        $learner = Learner::factory()->for($institution)->create();
        Sanctum::actingAs($user, ['portal:access']);

        $response = $this->postJson(route('api.v1.devices.sessions.store', $device), [
            'learner_id' => $learner->id,
            'identity_source' => 'school_pin',
            'pin' => '1234',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['learner_id']);
        $this->assertDatabaseMissing('learner_sessions', ['device_id' => $device->id]);
    }
}
