<?php

namespace Tests\Feature\Api\V1;

use App\Enums\InstitutionStatus;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InstitutionIndexFilterTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cde_filters_the_register_by_status(): void
    {
        $pending = Institution::factory()->create(['status' => InstitutionStatus::PendingApproval]);
        Institution::factory()->create(['status' => InstitutionStatus::Approved]);
        Sanctum::actingAs(User::factory()->cde()->create(), ['portal:access']);

        $response = $this->getJson(route('api.v1.institutions.index', ['status' => 'pending_approval']));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id);
    }

    public function test_cde_searches_the_register_by_name_or_nemis_code(): void
    {
        $match = Institution::factory()->create(['name' => 'Githunguri Township Primary School']);
        Institution::factory()->create(['name' => 'Ruiru Model Secondary School']);
        Sanctum::actingAs(User::factory()->cde()->create(), ['portal:access']);

        $this->getJson(route('api.v1.institutions.index', ['search' => 'githunguri']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);

        $this->getJson(route('api.v1.institutions.index', ['search' => $match->nemis_code]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_filters_never_widen_a_subcounty_directors_visibility(): void
    {
        $visible = Institution::factory()->create(['status' => InstitutionStatus::PendingApproval]);
        $hidden = Institution::factory()->create(['status' => InstitutionStatus::PendingApproval]);
        Sanctum::actingAs(User::factory()->scde($visible->subcounty()->firstOrFail())->create(), ['portal:access']);

        $response = $this->getJson(route('api.v1.institutions.index', [
            'status' => 'pending_approval',
            'subcounty_id' => $hidden->subcounty_id,
        ]));

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_rejects_an_unknown_status_filter(): void
    {
        Sanctum::actingAs(User::factory()->cde()->create(), ['portal:access']);

        $this->getJson(route('api.v1.institutions.index', ['status' => 'not-a-status']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }
}
