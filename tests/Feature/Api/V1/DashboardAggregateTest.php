<?php

namespace Tests\Feature\Api\V1;

use App\Enums\InstitutionStatus;
use App\Models\ContentCategory;
use App\Models\Device;
use App\Models\Incident;
use App\Models\Institution;
use App\Models\ProtectionComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardAggregateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dashboard_reports_the_county_protection_posture(): void
    {
        $protected = Institution::factory()->create(['status' => InstitutionStatus::Protected]);
        Institution::factory()->create(['status' => InstitutionStatus::AttributionRequired]);
        Device::factory()->create(['institution_id' => $protected->id, 'status' => 'offline']);
        ProtectionComponent::factory()->degraded()->create(['institution_id' => $protected->id, 'type' => 'endpoint_agent']);
        Incident::factory()->forInstitution($protected)->create(['severity' => 'critical', 'status' => 'open']);
        Sanctum::actingAs(User::factory()->cde()->create(), ['portal:access']);

        $this->getJson(route('api.v1.dashboard'))
            ->assertOk()
            ->assertJsonPath('data.institutions', 2)
            ->assertJsonPath('data.institutions_by_status.protected', 1)
            ->assertJsonPath('data.institutions_by_status.attribution_required', 1)
            ->assertJsonPath('data.devices_by_status.offline', 1)
            ->assertJsonPath('data.components_by_health.degraded', 1)
            ->assertJsonPath('data.components_by_type.endpoint_agent.degraded', 1)
            ->assertJsonPath('data.open_incidents_by_severity.critical', 1)
            // The offline device plus the device the incident was raised against.
            ->assertJsonPath('data.devices', 2)
            ->assertJsonPath('data.unattributed_devices', 2)
            ->assertJsonPath('data.attributed_devices', 0);
    }

    public function test_dashboard_aggregates_are_limited_to_the_officers_own_institution(): void
    {
        $institution = Institution::factory()->create(['status' => InstitutionStatus::Protected]);
        Device::factory()->create(['institution_id' => $institution->id, 'status' => 'offline']);
        Device::factory()->create(['status' => 'offline']);
        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);

        $this->getJson(route('api.v1.dashboard'))
            ->assertOk()
            ->assertJsonPath('data.institutions', 1)
            ->assertJsonPath('data.devices', 1)
            ->assertJsonPath('data.devices_by_status.offline', 1);
    }

    public function test_incident_trend_returns_one_point_per_day_with_leading_categories(): void
    {
        $institution = Institution::factory()->create();
        $category = ContentCategory::factory()->create(['name' => 'Gambling']);
        Incident::factory()->forInstitution($institution)->create([
            'content_category_id' => $category->id,
            'first_detected_at' => now()->subDays(2),
        ]);
        Sanctum::actingAs(User::factory()->cde()->create(), ['portal:access']);

        $response = $this->getJson(route('api.v1.reports.incident-trend', ['days' => 14]));

        $response->assertOk()
            ->assertJsonPath('data.days', 14)
            ->assertJsonPath('data.total', 1)
            ->assertJsonCount(14, 'data.series')
            ->assertJsonPath('data.categories.0.label', 'Gambling')
            ->assertJsonPath('data.categories.0.count', 1);

        $day = collect($response->json('data.series'))->firstWhere('date', now()->subDays(2)->toDateString());
        $this->assertSame(1, $day['count']);
    }

    public function test_incident_trend_rejects_a_window_outside_the_supported_range(): void
    {
        Sanctum::actingAs(User::factory()->cde()->create(), ['portal:access']);

        $this->getJson(route('api.v1.reports.incident-trend', ['days' => 400]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('days');
    }

    public function test_subcounty_index_carries_the_protection_figures_the_overview_renders(): void
    {
        $institution = Institution::factory()->create(['status' => InstitutionStatus::Protected]);
        Device::factory()->create(['institution_id' => $institution->id]);
        Institution::factory()->create(['subcounty_id' => $institution->subcounty_id, 'status' => InstitutionStatus::Onboarding]);
        Sanctum::actingAs(User::factory()->cde()->create(), ['portal:access']);

        $this->getJson(route('api.v1.subcounties.index'))
            ->assertOk()
            ->assertJsonPath('data.0.institutions_count', 2)
            ->assertJsonPath('data.0.protected_institutions_count', 1)
            ->assertJsonPath('data.0.devices_count', 1)
            ->assertJsonPath('data.0.unattributed_devices_count', 1)
            ->assertJsonPath('data.0.open_incidents_count', 0);
    }
}
