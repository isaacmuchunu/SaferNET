<?php

namespace Tests\Feature\Api\V1;

use App\Models\Device;
use App\Models\FilteringPolicy;
use App\Models\Institution;
use App\Models\Laboratory;
use App\Models\Learner;
use App\Models\ProtectionComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClassroomAndExtensionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_clm_can_fetch_live_classroom_stream(): void
    {
        $institution = Institution::factory()->create();
        $clm = User::factory()->clm($institution)->create();
        $device = Device::factory()->create(['institution_id' => $institution->id]);
        $learner = Learner::factory()->create(['institution_id' => $institution->id]);

        Sanctum::actingAs($clm, ['portal:access']);

        $response = $this->getJson(route('api.v1.classrooms.live'));

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'laboratories',
                'active_count',
                'tiles',
                'active_broadcast',
            ],
        ]);
    }

    public function test_clm_can_push_url_to_classroom(): void
    {
        $institution = Institution::factory()->create();
        $clm = User::factory()->clm($institution)->create();

        Sanctum::actingAs($clm, ['portal:access']);

        $response = $this->postJson(route('api.v1.classrooms.push-url'), [
            'url' => 'https://en.wikipedia.org/wiki/Kenya',
            'title' => 'History of Kenya',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.pushed', true);
        $response->assertJsonPath('data.url', 'https://en.wikipedia.org/wiki/Kenya');

        $service = User::factory()->service($institution)->create();
        Sanctum::actingAs($service, ['telemetry:write']);

        $this->getJson(route('api.v1.extension.commands'))
            ->assertOk()
            ->assertJsonPath('command.type', 'CLASSROOM_PUSH_URL')
            ->assertJsonPath('command.url', 'https://en.wikipedia.org/wiki/Kenya')
            ->assertJsonPath('focus_locked', false);
    }

    public function test_extension_sync_returns_filtering_rules(): void
    {
        $institution = Institution::factory()->create(['nemis_code' => 'KIKUYU001']);
        FilteringPolicy::factory()->create([
            'institution_id' => $institution->id,
            'status' => 'active',
            'level' => 'institution',
        ]);

        $service = User::factory()->service($institution)->create();
        Sanctum::actingAs($service, ['telemetry:write']);

        $response = $this->getJson(route('api.v1.extension.sync', ['school_id' => 'ANOTHER-SCHOOL']));

        $response->assertOk();
        $response->assertJsonStructure([
            'revision',
            'institution',
            'nemis_code',
            'enforce_safesearch',
            'enforce_youtube_strict',
            'dnr_rules',
        ]);
        $response->assertJsonPath('institution_id', $institution->id);
        $response->assertJsonPath('nemis_code', 'KIKUYU001');
    }

    public function test_extension_endpoints_require_an_institution_service_token(): void
    {
        $this->getJson(route('api.v1.extension.sync'))->assertUnauthorized();

        $institution = Institution::factory()->create();
        $portalUser = User::factory()->hoi($institution)->create();
        Sanctum::actingAs($portalUser, ['portal:access']);

        $this->getJson(route('api.v1.extension.sync'))->assertForbidden();
    }

    public function test_extension_heartbeat_writes_the_real_component_schema_in_its_own_tenant(): void
    {
        $institution = Institution::factory()->create();
        $service = User::factory()->service($institution)->create();
        Sanctum::actingAs($service, ['telemetry:write']);

        $this->postJson(route('api.v1.extension.heartbeat'), [
            'workstation_id' => 'LAB-A-07',
            'version' => '2.6.0',
            'rules_count' => 83,
        ])->assertOk();

        $component = ProtectionComponent::withoutGlobalScopes()->sole();
        $this->assertSame($institution->id, $component->institution_id);
        $this->assertSame('browser_extension', $component->type);
        $this->assertSame('LAB-A-07', $component->identifier);
        $this->assertSame(83, $component->metadata['rules_count']);
    }

    public function test_school_officer_cannot_view_another_schools_laboratory_or_classroom(): void
    {
        $institution = Institution::factory()->create();
        $otherLaboratory = Laboratory::factory()->create();
        $clm = User::factory()->clm($institution)->create();
        Sanctum::actingAs($clm, ['portal:access']);

        $this->getJson(route('api.v1.classrooms.live', ['laboratory_id' => $otherLaboratory->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('laboratory_id');

        $scde = User::factory()->scde($institution->subcounty)->create();
        Sanctum::actingAs($scde, ['portal:access']);

        $this->getJson(route('api.v1.classrooms.live'))->assertForbidden();
    }

    public function test_agent_can_resolve_device_by_asset_tag_or_uuid(): void
    {
        $institution = Institution::factory()->create();
        $device = Device::factory()->create([
            'institution_id' => $institution->id,
            'asset_tag' => 'ICTLAB-TEST-01',
        ]);
        $service = User::factory()->service($institution)->create();
        Sanctum::actingAs($service, ['telemetry:write']);

        // Resolve by asset tag
        $response = $this->getJson(route('api.v1.agent.resolve-device', ['identifier' => 'ICTLAB-TEST-01']));
        $response->assertOk();
        $response->assertJsonPath('data.id', $device->id);
        $response->assertJsonPath('data.asset_tag', 'ICTLAB-TEST-01');

        // Resolve by UUID
        $responseUuid = $this->getJson(route('api.v1.agent.resolve-device', ['identifier' => $device->public_id]));
        $responseUuid->assertOk();
        $responseUuid->assertJsonPath('data.id', $device->id);

        // Unknown device returns 404
        $responseUnknown = $this->getJson(route('api.v1.agent.resolve-device', ['identifier' => 'NON-EXISTENT']));
        $responseUnknown->assertNotFound();
    }
}
