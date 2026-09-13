<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\SendOfficerProvisioningMessages;
use App\Models\ContentCategory;
use App\Models\ExceptionRequest;
use App\Models\FilteringPolicy;
use App\Models\Incident;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Head of Institution governs and acts on what SAFERNET reports; the
 * Computer Laboratory Manager deploys, maintains and troubleshoots it. These
 * tests pin the boundary between the two offices.
 */
class RoleSeparationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_laboratory_manager_operates_the_technology(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs(User::factory()->clm($institution)->create(), ['portal:access']);

        $this->postJson(route('api.v1.laboratories.store'), ['name' => 'Computer Laboratory 3'])->assertCreated();
        $this->postJson(route('api.v1.device-groups.store'), ['name' => 'Examination Terminals'])->assertCreated();
        $this->postJson(route('api.v1.learner-groups.store'), ['name' => 'Grade 8 West', 'academic_year' => '2026'])->assertCreated();
        $this->postJson(route('api.v1.devices.store'), ['asset_tag' => 'ICTLAB-PC900'])->assertCreated();
        $this->postJson(route('api.v1.learners.store'), [
            'learner_number' => 'STU-900',
            'first_name' => 'Grace',
            'last_name' => 'Wambui',
        ])->assertCreated();
    }

    public function test_laboratory_manager_may_not_author_filtering_policy(): void
    {
        $institution = Institution::factory()->create();
        $policy = FilteringPolicy::factory()->create([
            'institution_id' => $institution->id,
            'level' => 'institution',
        ]);
        $category = ContentCategory::factory()->create();
        Sanctum::actingAs(User::factory()->clm($institution)->create(), ['portal:access']);

        $this->postJson(route('api.v1.filtering-policies.store'), ['name' => 'Relaxed policy', 'level' => 'institution'])
            ->assertForbidden();

        $this->postJson(route('api.v1.filtering-policies.rules.store', $policy), [
            'content_category_id' => $category->id,
            'action' => 'allow',
            'severity' => 'low',
        ])->assertForbidden();

        // Reading the rules is allowed: they explain why a site was blocked.
        $this->getJson(route('api.v1.filtering-policies.index'))->assertOk();
    }

    public function test_laboratory_manager_may_not_record_action_on_a_learner_incident(): void
    {
        $institution = Institution::factory()->create();
        $incident = Incident::factory()->forInstitution($institution)->create();
        Sanctum::actingAs(User::factory()->clm($institution)->create(), ['portal:access']);

        $this->postJson(route('api.v1.incidents.actions.store', $incident), ['action' => 'counselled'])->assertForbidden();
        $this->patchJson(route('api.v1.incidents.update', $incident), ['status' => 'resolved'])->assertForbidden();

        // The evidence itself stays readable for technical verification.
        $this->getJson(route('api.v1.incidents.show', $incident))->assertOk();
    }

    public function test_head_of_institution_governs_filtering_and_incidents(): void
    {
        $institution = Institution::factory()->create();
        $incident = Incident::factory()->forInstitution($institution)->create();
        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);

        $this->postJson(route('api.v1.filtering-policies.store'), ['name' => 'School policy', 'level' => 'institution'])
            ->assertCreated();
        $this->postJson(route('api.v1.incidents.actions.store', $incident), [
            'action' => 'counselled',
            'notes' => 'Learner counselled and guardian informed.',
        ])->assertCreated();
    }

    public function test_head_of_institution_approves_an_exception_for_their_own_school_only(): void
    {
        $institution = Institution::factory()->create();
        $own = ExceptionRequest::factory()->create(['institution_id' => $institution->id]);
        $foreign = ExceptionRequest::factory()->create();
        $hoi = User::factory()->hoi($institution)->create();
        Sanctum::actingAs($hoi, ['portal:access']);

        $this->postJson(route('api.v1.exception-requests.reviews.store', $own), ['decision' => 'approved'])->assertOk();
        $this->assertDatabaseHas('exception_requests', ['id' => $own->id, 'status' => 'approved', 'reviewed_by' => $hoi->id]);

        $this->postJson(route('api.v1.exception-requests.reviews.store', $foreign), ['decision' => 'approved'])->assertNotFound();
    }

    public function test_laboratory_manager_may_submit_but_not_decide_an_exception(): void
    {
        $institution = Institution::factory()->create();
        $request = ExceptionRequest::factory()->create(['institution_id' => $institution->id]);
        Sanctum::actingAs(User::factory()->clm($institution)->create(), ['portal:access']);

        $this->postJson(route('api.v1.exception-requests.store'), [
            'domain' => 'scratch.mit.edu',
            'reason' => 'Required for the computing strand.',
        ])->assertCreated();

        $this->postJson(route('api.v1.exception-requests.reviews.store', $request), ['decision' => 'approved'])->assertForbidden();
    }

    public function test_every_officer_may_maintain_their_own_profile(): void
    {
        $institution = Institution::factory()->create();
        $clm = User::factory()->clm($institution)->create();
        Sanctum::actingAs($clm, ['portal:access']);

        $this->putJson(route('api.v1.users.update', $clm), ['name' => 'Updated Name'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $clm->id, 'name' => 'Updated Name']);

        // Office and scope stay with the officer who provisioned the account.
        $this->putJson(route('api.v1.users.update', $clm), ['role' => 'hoi'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $clm->id, 'role' => 'clm']);
    }

    public function test_an_officer_cannot_delete_their_own_account(): void
    {
        $hoi = User::factory()->hoi()->create();
        Sanctum::actingAs($hoi, ['portal:access']);

        $this->deleteJson(route('api.v1.users.destroy', $hoi))->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $hoi->id]);
    }

    public function test_head_of_institution_may_only_provision_laboratory_managers(): void
    {
        Queue::fake([SendOfficerProvisioningMessages::class]);
        $institution = Institution::factory()->create();
        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);

        $this->postJson(route('api.v1.users.store'), [
            'name' => 'Lab Manager',
            'email' => 'newclm@safernet.go.ke',
            'phone' => '0712 345 678',
            'role' => 'clm',
        ])->assertCreated();

        $this->postJson(route('api.v1.users.store'), [
            'name' => 'Another Head',
            'email' => 'newhoi@safernet.go.ke',
            'phone' => '0712 345 679',
            'role' => 'hoi',
        ])->assertForbidden();
    }
}
