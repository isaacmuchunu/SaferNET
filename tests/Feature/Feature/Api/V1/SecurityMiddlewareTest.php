<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Enums\InstitutionStatus;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\Laboratory;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityMiddlewareTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_suspended_account_cannot_use_an_existing_token(): void
    {
        $user = User::factory()->cde()->create(['status' => 'suspended']);
        Sanctum::actingAs($user, ['portal:access']);

        $this->getJson(route('api.v1.me'))->assertForbidden();
    }

    public function test_an_unapproved_institution_cannot_operate_its_registers(): void
    {
        $institution = Institution::factory()->create(['status' => InstitutionStatus::PendingApproval]);
        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);

        $this->getJson(route('api.v1.laboratories.index'))->assertForbidden();
        $this->getJson(route('api.v1.me'))->assertOk();
    }

    public function test_an_institution_in_deployment_may_still_operate_its_registers(): void
    {
        $institution = Institution::factory()->create(['status' => InstitutionStatus::DeploymentInProgress]);
        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);

        $this->getJson(route('api.v1.laboratories.index'))->assertOk();
    }

    public function test_a_portal_token_cannot_submit_telemetry(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);

        $this->postJson(route('api.v1.security-events.store'), [])->assertForbidden();
    }

    public function test_a_service_token_cannot_reach_the_officer_portal(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs(User::factory()->service($institution)->create(), ['telemetry:write']);

        $this->getJson(route('api.v1.institutions.index'))->assertForbidden();
    }

    public function test_audit_logs_are_withheld_from_laboratory_managers(): void
    {
        $institution = Institution::factory()->create();
        AuditLog::factory()->create(['institution_id' => $institution->id]);

        Sanctum::actingAs(User::factory()->clm($institution)->create(), ['portal:access']);
        $this->getJson(route('api.v1.audit-logs.index'))->assertForbidden();

        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);
        $this->getJson(route('api.v1.audit-logs.index'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_successful_mutation_is_written_to_the_audit_log(): void
    {
        $institution = Institution::factory()->create();
        $hoi = User::factory()->hoi($institution)->create();
        Sanctum::actingAs($hoi, ['portal:access']);

        $this->postJson(route('api.v1.laboratories.store'), ['name' => 'Computer Laboratory 2'])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $hoi->id,
            'institution_id' => $institution->id,
            'event' => 'api.api.v1.laboratories.store',
        ]);
    }

    public function test_a_rejected_mutation_is_not_written_to_the_audit_log(): void
    {
        $institution = Institution::factory()->create();
        $laboratory = Laboratory::factory()->create();
        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);

        // Tenant-scoped route binding hides another school's laboratory entirely.
        $this->deleteJson(route('api.v1.laboratories.destroy', $laboratory))->assertNotFound();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_reads_are_not_written_to_the_audit_log(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);

        $this->getJson(route('api.v1.laboratories.index'))->assertOk();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_repeated_failed_sign_ins_are_throttled(): void
    {
        $user = User::factory()->cde()->create();

        foreach (range(1, 5) as $ignored) {
            $this->postJson(route('api.v1.auth.login'), [
                'email' => $user->email,
                'password' => 'not-the-password',
                'device_name' => 'Officer Laptop',
            ])->assertUnprocessable();
        }

        $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'not-the-password',
            'device_name' => 'Officer Laptop',
        ])->assertStatus(429);
    }
}
