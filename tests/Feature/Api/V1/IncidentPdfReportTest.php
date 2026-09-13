<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\Institution;
use App\Models\Learner;
use App\Models\LearnerSession;
use App\Models\User;
use App\Models\WebEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncidentPdfReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_preparer_can_add_surrounding_activity_as_context(): void
    {
        [$officer, $incident] = $this->dossier();

        $linked = $this->event($incident, 'bet-example.co.ke', now()->subMinutes(5), $incident->id);
        $context = $this->event($incident, 'proxy-example.net', now()->subMinutes(30));

        Sanctum::actingAs($officer, ['portal:access']);

        $this->get(route('api.v1.incidents.report', [
            'incident' => $incident->public_id,
            'events' => [$context->event_uuid],
        ]))->assertOk()->assertHeader('content-type', 'application/pdf');

        // The export records what it contained, so it can be reconstructed from
        // the log rather than guessed at.
        $log = AuditLog::withoutGlobalScopes()->where('event', 'incident.dossier.exported')->latest('id')->sole();
        $this->assertSame(1, $log->new_values['linked_events']);
        $this->assertSame(1, $log->new_values['added_events']);
        $this->assertSame([$context->event_uuid], $log->new_values['added_event_uuids']);
        $this->assertSame($linked->event_uuid, $incident->webEvents()->sole()->event_uuid);
    }

    public function test_a_preparer_cannot_pull_another_learners_browsing_into_a_dossier(): void
    {
        [$officer, $incident] = $this->dossier();

        // Same school, different child. Selecting their traffic must not place
        // it in another learner's safeguarding record.
        $otherLearner = Learner::factory()->for($incident->institution)->create();
        $foreign = $this->event($incident, 'unrelated.example', now()->subMinutes(10), null, $otherLearner->id);

        Sanctum::actingAs($officer, ['portal:access']);

        $this->get(route('api.v1.incidents.report', [
            'incident' => $incident->public_id,
            'events' => [$foreign->event_uuid],
        ]))->assertOk();

        $log = AuditLog::withoutGlobalScopes()->where('event', 'incident.dossier.exported')->latest('id')->sole();
        $this->assertSame(0, $log->new_values['added_events']);
        $this->assertSame([], $log->new_values['added_event_uuids']);
    }

    public function test_an_event_already_linked_is_not_printed_twice(): void
    {
        [$officer, $incident] = $this->dossier();
        $linked = $this->event($incident, 'bet-example.co.ke', now()->subMinutes(5), $incident->id);

        Sanctum::actingAs($officer, ['portal:access']);

        $this->get(route('api.v1.incidents.report', [
            'incident' => $incident->public_id,
            'events' => [$linked->event_uuid],
        ]))->assertOk();

        $log = AuditLog::withoutGlobalScopes()->where('event', 'incident.dossier.exported')->latest('id')->sole();
        $this->assertSame(1, $log->new_values['linked_events']);
        $this->assertSame(0, $log->new_values['added_events'], 'A linked event must not also appear as added context.');
    }

    public function test_the_evidence_order_is_recorded_and_an_arbitrary_column_is_refused(): void
    {
        [$officer, $incident] = $this->dossier();
        $this->event($incident, 'bet-example.co.ke', now()->subMinutes(5), $incident->id);

        Sanctum::actingAs($officer, ['portal:access']);

        $this->get(route('api.v1.incidents.report', [
            'incident' => $incident->public_id,
            'sort' => 'domain',
            'direction' => 'asc',
        ]))->assertOk();

        $log = AuditLog::withoutGlobalScopes()->where('event', 'incident.dossier.exported')->latest('id')->sole();
        $this->assertSame('domain', $log->new_values['sorted_by']);
        $this->assertSame('asc', $log->new_values['direction']);

        // An arbitrary column is refused rather than reaching the query builder.
        $this->getJson(route('api.v1.incidents.report', [
            'incident' => $incident->public_id,
            'sort' => 'institution_id',
        ]))->assertUnprocessable();
    }

    /** @return array{User, Incident} */
    private function dossier(): array
    {
        $institution = Institution::factory()->create();
        $incident = Incident::factory()->forInstitution($institution)->create(['severity' => 'high']);

        return [User::factory()->cde()->create(), $incident];
    }

    private function event(Incident $incident, string $domain, $occurredAt, ?int $incidentId = null, ?int $learnerId = null): WebEvent
    {
        $session = LearnerSession::create([
            'institution_id' => $incident->institution_id,
            'device_id' => $incident->device_id,
            'learner_id' => $learnerId ?? $incident->learner_id,
            'identity_source' => 'school_pin',
            'started_at' => now()->subHours(2),
        ]);

        return WebEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'institution_id' => $incident->institution_id,
            'learner_session_id' => $session->id,
            'learner_id' => $learnerId ?? $incident->learner_id,
            'device_id' => $incident->device_id,
            'incident_id' => $incidentId,
            'url' => "https://{$domain}/page",
            'domain' => $domain,
            'request_kind' => 'top_level',
            'action' => 'block',
            'enforcement_source' => 'extension',
            'severity' => 'high',
            'reason' => 'Test fixture',
            'occurred_at' => $occurredAt,
        ]);
    }

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
