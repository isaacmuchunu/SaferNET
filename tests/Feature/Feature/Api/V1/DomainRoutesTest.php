<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Jobs\SendOfficerProvisioningMessages;
use App\Models\ContentCategory;
use App\Models\Device;
use App\Models\ExceptionRequest;
use App\Models\Incident;
use App\Models\Institution;
use App\Models\Laboratory;
use App\Models\Learner;
use App\Models\LearnerGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DomainRoutesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_head_of_institution_builds_the_school_register(): void
    {
        $institution = Institution::factory()->create();
        $hoi = User::factory()->hoi($institution)->create();
        Sanctum::actingAs($hoi, ['portal:access']);

        $group = $this->postJson(route('api.v1.learner-groups.store'), [
            'name' => 'Grade 7 East',
            'grade_level' => '7',
            'academic_year' => '2026',
        ])->assertCreated()->json('data.id');

        $this->postJson(route('api.v1.learners.store'), [
            'learner_group_id' => $group,
            'learner_number' => 'STU-000123',
            'first_name' => 'Wanjiku',
            'last_name' => 'Kamau',
            'pin' => '4821',
        ])->assertCreated();

        $laboratory = $this->postJson(route('api.v1.laboratories.store'), [
            'name' => 'Computer Laboratory 1',
            'location' => 'Science Wing',
        ])->assertCreated()->json('data.id');

        $this->postJson(route('api.v1.devices.store'), [
            'laboratory_id' => $laboratory,
            'asset_tag' => 'ICTLAB-PC001',
            'platform' => 'windows',
        ])->assertCreated();

        $this->assertDatabaseHas('learners', [
            'institution_id' => $institution->id,
            'learner_number' => 'STU-000123',
            'learner_group_id' => $group,
        ]);
        $this->assertDatabaseHas('devices', [
            'institution_id' => $institution->id,
            'asset_tag' => 'ICTLAB-PC001',
            'laboratory_id' => $laboratory,
        ]);
    }

    public function test_register_writes_are_confined_to_the_officers_own_institution(): void
    {
        $institution = Institution::factory()->create();
        $otherInstitution = Institution::factory()->create();
        $hoi = User::factory()->hoi($institution)->create();
        Sanctum::actingAs($hoi, ['portal:access']);

        $this->postJson(route('api.v1.laboratories.store'), [
            'institution_id' => $otherInstitution->id,
            'name' => 'Borrowed Laboratory',
        ])->assertNotFound();

        $this->assertDatabaseMissing('laboratories', ['name' => 'Borrowed Laboratory']);
    }

    public function test_learner_group_from_another_institution_cannot_be_attached_to_a_learner(): void
    {
        $institution = Institution::factory()->create();
        $foreignGroup = LearnerGroup::factory()->create();
        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);

        $this->postJson(route('api.v1.learners.store'), [
            'learner_group_id' => $foreignGroup->id,
            'learner_number' => 'STU-000999',
            'first_name' => 'Otieno',
            'last_name' => 'Achieng',
        ])->assertNotFound();
    }

    public function test_subcounty_director_sees_only_their_own_subcounty_registers(): void
    {
        $institution = Institution::factory()->create();
        $visible = Laboratory::factory()->create(['institution_id' => $institution->id]);
        Laboratory::factory()->create();
        Sanctum::actingAs(User::factory()->scde($institution->subcounty)->create(), ['portal:access']);

        $response = $this->getJson(route('api.v1.laboratories.index'));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_incident_action_resolves_the_incident(): void
    {
        $institution = Institution::factory()->create();
        $incident = Incident::factory()->forInstitution($institution)->create();
        $hoi = User::factory()->hoi($institution)->create();
        Sanctum::actingAs($hoi, ['portal:access']);

        $this->postJson(route('api.v1.incidents.actions.store', $incident), [
            'action' => 'resolved',
            'notes' => 'Guardian contacted and learner counselled.',
        ])->assertCreated();

        $this->assertDatabaseHas('incidents', [
            'id' => $incident->id,
            'status' => 'resolved',
            'resolved_by' => $hoi->id,
        ]);
        $this->assertDatabaseHas('incident_actions', [
            'incident_id' => $incident->id,
            'actor_id' => $hoi->id,
            'action' => 'resolved',
        ]);
    }

    public function test_incident_list_is_filtered_by_status_and_severity(): void
    {
        $institution = Institution::factory()->create();
        $critical = Incident::factory()->forInstitution($institution)->create(['severity' => 'critical']);
        Incident::factory()->forInstitution($institution)->create(['severity' => 'low', 'status' => 'resolved']);
        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);

        $this->getJson(route('api.v1.incidents.index', ['status' => 'open', 'severity' => 'critical']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $critical->public_id);
    }

    public function test_county_director_reviews_an_exception_request(): void
    {
        $institution = Institution::factory()->create();
        $request = ExceptionRequest::factory()->create(['institution_id' => $institution->id]);
        $cde = User::factory()->cde()->create();
        Sanctum::actingAs($cde, ['portal:access']);

        $this->postJson(route('api.v1.exception-requests.reviews.store', $request), [
            'decision' => 'approved',
            'review_notes' => 'Domain is required for the national curriculum.',
        ])->assertOk();

        $this->assertDatabaseHas('exception_requests', [
            'id' => $request->id,
            'status' => 'approved',
            'reviewed_by' => $cde->id,
        ]);
    }

    public function test_an_already_reviewed_exception_request_cannot_be_reviewed_again(): void
    {
        $request = ExceptionRequest::factory()->reviewed()->create();
        Sanctum::actingAs(User::factory()->cde()->create(), ['portal:access']);

        $this->postJson(route('api.v1.exception-requests.reviews.store', $request), ['decision' => 'rejected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision');
    }

    public function test_county_director_publishes_a_county_filtering_policy_with_a_locked_rule(): void
    {
        $category = ContentCategory::factory()->create();
        Sanctum::actingAs(User::factory()->cde()->create(), ['portal:access']);

        $policy = $this->postJson(route('api.v1.filtering-policies.store'), [
            'name' => 'Kiambu County Baseline',
            'level' => 'county',
            'status' => 'active',
        ])->assertCreated()->json('data.id');

        $this->postJson(route('api.v1.filtering-policies.rules.store', $policy), [
            'content_category_id' => $category->id,
            'action' => 'block',
            'severity' => 'critical',
            'is_locked' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('filtering_policies', [
            'id' => $policy,
            'level' => 'county',
            'institution_id' => null,
        ]);
        $this->assertDatabaseHas('policy_rules', ['filtering_policy_id' => $policy, 'is_locked' => true]);
    }

    public function test_head_of_institution_may_only_enrol_laboratory_managers(): void
    {
        Queue::fake([SendOfficerProvisioningMessages::class]);
        $institution = Institution::factory()->create();
        $hoi = User::factory()->hoi($institution)->create();
        Sanctum::actingAs($hoi, ['portal:access']);

        $this->postJson(route('api.v1.users.store'), [
            'name' => 'Laboratory Manager',
            'email' => 'clm@education.go.ke',
            'phone' => '0712 345 678',
            'role' => 'clm',
        ])->assertCreated();

        $this->postJson(route('api.v1.users.store'), [
            'name' => 'Rogue Director',
            'email' => 'rogue@education.go.ke',
            'phone' => '0712 345 679',
            'role' => 'scde',
            'subcounty_id' => $institution->subcounty_id,
        ])->assertForbidden();

        $this->assertDatabaseHas('users', ['email' => 'clm@education.go.ke', 'institution_id' => $institution->id]);
        $this->assertDatabaseMissing('users', ['email' => 'rogue@education.go.ke']);
    }

    public function test_protection_summary_report_counts_only_visible_records(): void
    {
        $institution = Institution::factory()->create();
        Learner::factory()->count(2)->create(['institution_id' => $institution->id]);
        Device::factory()->create(['institution_id' => $institution->id]);
        Learner::factory()->create();
        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);

        $this->getJson(route('api.v1.reports.protection-summary'))
            ->assertOk()
            ->assertJsonPath('data.learners', 2)
            ->assertJsonPath('data.devices', 1);
    }

    public function test_content_categories_are_readable_by_every_officer(): void
    {
        ContentCategory::factory()->create(['name' => 'Gambling']);
        Sanctum::actingAs(User::factory()->clm()->create(), ['portal:access']);

        $this->getJson(route('api.v1.content-categories.index'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Gambling');
    }
}
