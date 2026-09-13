<?php

namespace Tests\Feature\Api\V1;

use App\Models\Device;
use App\Models\DeviceLearnerAssignment;
use App\Models\Institution;
use App\Models\Learner;
use App\Models\Subcounty;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HierarchicalTenantIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_school_dashboard_only_counts_its_own_records(): void
    {
        [$firstInstitution, $secondInstitution] = $this->institutionsInDifferentSubcounties();
        Device::factory()->count(2)->for($firstInstitution)->create();
        Device::factory()->count(5)->for($secondInstitution)->create();
        Learner::factory()->count(3)->for($firstInstitution)->create();
        Learner::factory()->count(7)->for($secondInstitution)->create();
        Sanctum::actingAs(User::factory()->hoi($firstInstitution)->create(), ['portal:access']);

        $this->getJson(route('api.v1.dashboard'))
            ->assertOk()
            ->assertJsonPath('data.institutions', 1)
            ->assertJsonPath('data.devices', 2)
            ->assertJsonPath('data.learners', 3);
    }

    public function test_scde_sees_all_schools_in_its_subcounty_and_none_in_another(): void
    {
        $subcounty = Subcounty::factory()->create();
        $otherSubcounty = Subcounty::factory()->create();
        $first = Institution::factory()->for($subcounty)->create();
        $second = Institution::factory()->for($subcounty)->create();
        $outside = Institution::factory()->for($otherSubcounty)->create();
        Device::factory()->for($first)->create();
        Device::factory()->count(2)->for($second)->create();
        Device::factory()->count(4)->for($outside)->create();
        Sanctum::actingAs(User::factory()->scde($subcounty)->create(), ['portal:access']);

        $this->getJson(route('api.v1.dashboard'))
            ->assertOk()
            ->assertJsonPath('data.institutions', 2)
            ->assertJsonPath('data.devices', 3);

        $this->getJson(route('api.v1.institutions.show', $outside))->assertNotFound();
    }

    public function test_tenant_aware_binding_hides_another_schools_device(): void
    {
        [$firstInstitution, $secondInstitution] = $this->institutionsInDifferentSubcounties();
        $user = User::factory()->clm($firstInstitution)->create();
        $outsideDevice = Device::factory()->for($secondInstitution)->create();
        $outsideLearner = Learner::factory()->for($secondInstitution)->create();
        Sanctum::actingAs($user, ['portal:access']);

        $this->postJson(route('api.v1.devices.assignments.store', $outsideDevice), [
            'learner_id' => $outsideLearner->id,
        ])->assertNotFound();
    }

    public function test_postgresql_rejects_cross_institution_assignment_rows(): void
    {
        [$firstInstitution, $secondInstitution] = $this->institutionsInDifferentSubcounties();
        $device = Device::factory()->for($firstInstitution)->create();
        $learner = Learner::factory()->for($secondInstitution)->create();
        $actor = User::factory()->cde()->create();

        $this->expectException(QueryException::class);

        DeviceLearnerAssignment::query()->insert([
            'institution_id' => $firstInstitution->id,
            'device_id' => $device->id,
            'learner_id' => $learner->id,
            'assigned_by' => $actor->id,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_global_tenant_scope_fails_closed_to_the_current_school(): void
    {
        [$firstInstitution, $secondInstitution] = $this->institutionsInDifferentSubcounties();
        Device::factory()->count(2)->for($firstInstitution)->create();
        Device::factory()->count(4)->for($secondInstitution)->create();
        $principal = User::factory()->hoi($firstInstitution)->create();
        app(TenantContext::class)->setPrincipal($principal);

        $this->assertSame(2, Device::query()->count());
        $this->assertNull(Device::query()->where('institution_id', $secondInstitution->id)->first());
    }

    public function test_postgresql_rejects_a_role_without_its_required_tenant_scope(): void
    {
        $this->expectException(QueryException::class);

        User::query()->insert([
            'name' => 'Unscoped SCDE',
            'email' => 'invalid-scope@safernet.test',
            'password' => 'not-used',
            'role' => 'scde',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{Institution, Institution} */
    private function institutionsInDifferentSubcounties(): array
    {
        return [
            Institution::factory()->for(Subcounty::factory())->create(),
            Institution::factory()->for(Subcounty::factory())->create(),
        ];
    }
}
