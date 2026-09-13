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

class DeviceAssignmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_assigns_an_institution_learner_and_records_audit_history(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->clm($institution)->create();
        $device = Device::factory()->for($institution)->create();
        $learner = Learner::factory()->for($institution)->create();
        Sanctum::actingAs($user, ['portal:access']);

        $response = $this->postJson(route('api.v1.devices.assignments.store', $device), [
            'learner_id' => $learner->id,
        ]);

        $response->assertOk()->assertJsonPath('data.assignment_capacity.used', 1);
        $this->assertDatabaseHas('device_learner_assignments', [
            'device_id' => $device->id,
            'learner_id' => $learner->id,
            'removed_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'device.learner_assigned', 'auditable_id' => $device->id]);
    }

    public function test_returns_422_when_third_learner_is_assigned(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->clm($institution)->create();
        $device = Device::factory()->for($institution)->create();
        $learners = Learner::factory()->count(3)->for($institution)->create();
        foreach ($learners->take(2) as $learner) {
            DeviceLearnerAssignment::factory()->create([
                'device_id' => $device->id,
                'learner_id' => $learner->id,
                'assigned_by' => $user->id,
            ]);
        }
        Sanctum::actingAs($user, ['portal:access']);

        $response = $this->postJson(route('api.v1.devices.assignments.store', $device), [
            'learner_id' => $learners->last()->id,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['learner_id']);
        $this->assertSame(2, $device->activeAssignments()->count());
    }

    public function test_returns_404_for_cross_institution_device(): void
    {
        $userInstitution = Institution::factory()->create();
        $otherInstitution = Institution::factory()->create();
        $user = User::factory()->clm($userInstitution)->create();
        $device = Device::factory()->for($otherInstitution)->create();
        $learner = Learner::factory()->for($otherInstitution)->create();
        Sanctum::actingAs($user, ['portal:access']);

        $response = $this->postJson(route('api.v1.devices.assignments.store', $device), [
            'learner_id' => $learner->id,
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('device_learner_assignments', ['device_id' => $device->id]);
    }
}
