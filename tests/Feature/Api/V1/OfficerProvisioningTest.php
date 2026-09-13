<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\SendOfficerProvisioningMessages;
use App\Models\Institution;
use App\Models\Subcounty;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OfficerProvisioningTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cde_provisions_scde_with_generated_temporary_credentials(): void
    {
        Queue::fake([SendOfficerProvisioningMessages::class]);
        $subcounty = Subcounty::factory()->create();
        Sanctum::actingAs(User::factory()->cde()->create(), ['portal:access']);

        $this->postJson(route('api.v1.users.store'), [
            'name' => 'New Sub-County Director',
            'email' => 'new.scde@safernet.go.ke',
            'phone' => '0712 345 678',
            'role' => 'scde',
            'subcounty_id' => $subcounty->id,
            'status' => 'active',
        ])->assertCreated()->assertJsonPath('data.role', 'scde');

        $officer = User::withoutGlobalScopes()->where('email', 'new.scde@safernet.go.ke')->firstOrFail();
        $this->assertTrue($officer->must_change_password);
        $this->assertTrue($officer->mfa_required);
        $this->assertSame('+254712345678', $officer->phone);
        $this->assertNotNull($officer->temporary_password_expires_at);

        Queue::assertPushed(SendOfficerProvisioningMessages::class, function ($job) use ($officer): bool {
            return $job->userId === $officer->id
                && Hash::check(Crypt::decryptString($job->encryptedTemporaryPassword), $officer->password);
        });
    }

    public function test_scde_school_registration_provisions_the_head_of_institution_in_its_tenant(): void
    {
        Queue::fake([SendOfficerProvisioningMessages::class]);
        $subcounty = Subcounty::factory()->create();
        Sanctum::actingAs(User::factory()->scde($subcounty)->create(), ['portal:access']);

        $response = $this->postJson(route('api.v1.institutions.store'), [
            'subcounty_id' => $subcounty->id,
            'name' => 'Karura Primary School',
            'nemis_code' => 'KMB-2026-001',
            'institution_type' => 'primary',
            'ownership' => 'public',
            'physical_location' => 'Karura Ward',
            'hoi_name' => 'Mary Wanjiku',
            'hoi_email' => 'hoi@karura.test',
            'hoi_phone' => '0700 111 222',
            'learner_population' => 640,
            'computing_devices_count' => 32,
            'laboratories_count' => 1,
            'connectivity_type' => 'Fibre',
        ])->assertCreated();

        $institution = Institution::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $hoi = User::withoutGlobalScopes()->where('email', 'hoi@karura.test')->firstOrFail();
        $this->assertSame($institution->id, $hoi->institution_id);
        $this->assertSame($subcounty->id, $hoi->subcounty_id);
        $this->assertSame('hoi', $hoi->role->value);
        $this->assertTrue($hoi->must_change_password);

        Queue::assertPushed(SendOfficerProvisioningMessages::class, fn ($job): bool => $job->userId === $hoi->id);
    }

    public function test_hoi_provisions_clm_only_inside_its_school(): void
    {
        Queue::fake([SendOfficerProvisioningMessages::class]);
        $institution = Institution::factory()->create();
        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);

        $this->postJson(route('api.v1.users.store'), [
            'name' => 'Computer Lab Manager',
            'email' => 'clm@school.test',
            'phone' => '+254 711 222 333',
            'role' => 'clm',
            'status' => 'active',
        ])->assertCreated();

        $clm = User::withoutGlobalScopes()->where('email', 'clm@school.test')->firstOrFail();
        $this->assertSame($institution->id, $clm->institution_id);
        $this->assertSame($institution->subcounty_id, $clm->subcounty_id);
        $this->assertTrue($clm->mfa_required);

        Queue::assertPushed(SendOfficerProvisioningMessages::class, fn ($job): bool => $job->userId === $clm->id);
    }
}
