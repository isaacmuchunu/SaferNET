<?php

namespace Tests\Feature\Api\V1;

use App\Enums\InstitutionStatus;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InstitutionReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cde_approves_pending_institution_and_records_audit_history(): void
    {
        $institution = Institution::factory()->create(['status' => InstitutionStatus::PendingApproval]);
        $cde = User::factory()->cde()->create();
        Sanctum::actingAs($cde, ['portal:access']);

        $response = $this->postJson(route('api.v1.institutions.reviews.store', $institution), [
            'decision' => 'approve',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('institutions', [
            'id' => $institution->id,
            'status' => 'approved',
            'reviewed_by' => $cde->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'institution.reviewed', 'auditable_id' => $institution->id]);
    }

    public function test_returns_403_when_scde_attempts_approval(): void
    {
        $institution = Institution::factory()->create(['status' => InstitutionStatus::PendingApproval]);
        $scde = User::factory()->scde($institution->subcounty()->firstOrFail())->create();
        Sanctum::actingAs($scde, ['portal:access']);

        $response = $this->postJson(route('api.v1.institutions.reviews.store', $institution), [
            'decision' => 'approve',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('institutions', ['id' => $institution->id, 'status' => 'pending_approval']);
    }
}
