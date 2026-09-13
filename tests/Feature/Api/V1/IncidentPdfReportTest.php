<?php

namespace Tests\Feature\Api\V1;

use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncidentPdfReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cde_can_download_server_generated_incident_pdf_dossier(): void
    {
        $institution = Institution::factory()->create([
            'name' => 'Starehe Boys Centre',
            'nemis_code' => '99887766',
        ]);
        $incident = Incident::factory()->forInstitution($institution)->create([
            'severity' => 'high',
        ]);
        $learner = $incident->learner;
        $learner->update([
            'first_name' => 'Brian',
            'last_name' => 'Ochieng',
        ]);

        $officer = User::factory()->cde()->create(['name' => 'Director Jane']);

        IncidentAction::factory()->create([
            'institution_id' => $institution->id,
            'incident_id' => $incident->id,
            'actor_id' => $officer->id,
            'action' => 'parent_contacted',
            'notes' => 'Guardian called and scheduled for conference on Monday.',
        ]);

        Sanctum::actingAs($officer, ['portal:access']);

        $response = $this->get(route('api.v1.incidents.report', $incident));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline;', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incident.dossier.exported',
            'auditable_id' => $incident->id,
            'actor_id' => $officer->id,
        ]);
    }

    public function test_unauthenticated_report_request_is_rejected(): void
    {
        $institution = Institution::factory()->create();
        $incident = Incident::factory()->forInstitution($institution)->create();

        $response = $this->getJson(route('api.v1.incidents.report', $incident));
        $response->assertUnauthorized();
    }

    public function test_school_officer_cannot_export_another_schools_incident(): void
    {
        $ownInstitution = Institution::factory()->create();
        $otherIncident = Incident::factory()->forInstitution(Institution::factory()->create())->create();
        $officer = User::factory()->hoi($ownInstitution)->create();

        Sanctum::actingAs($officer, ['portal:access']);

        $this->getJson(route('api.v1.incidents.report', $otherIncident))->assertNotFound();
    }
}
