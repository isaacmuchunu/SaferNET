<?php

namespace Tests\Feature\Console;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SafernetCommandsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_device_enrolment_is_institution_scoped_and_audited(): void
    {
        $institution = Institution::factory()->create(['nemis_code' => '12345678']);

        $this->artisan('safernet:enroll-device', [
            'institution' => '12345678',
            'asset_tag' => 'LAB-01-PC-001',
            '--hostname' => 'LAB-01-PC-001',
        ])->assertSuccessful();

        $this->assertDatabaseHas('devices', [
            'institution_id' => $institution->id,
            'asset_tag' => 'LAB-01-PC-001',
            'platform' => 'windows',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'device.enrolled.cli', 'actor_id' => null]);
    }

    public function test_mfa_reset_clears_secret_and_revokes_sessions(): void
    {
        $user = User::factory()->cde()->create([
            'mfa_required' => true,
            'mfa_secret' => 'secret',
            'mfa_enabled_at' => now(),
            'mfa_recovery_codes' => ['code'],
        ]);
        $user->createToken('Browser', ['portal:access']);

        $this->artisan('safernet:reset-mfa', ['email' => $user->email, '--force' => true])->assertSuccessful();

        $user->refresh();
        $this->assertNull($user->mfa_secret);
        $this->assertNull($user->mfa_enabled_at);
        $this->assertCount(0, $user->tokens);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.mfa.cli_reset', 'actor_id' => null]);
    }

    public function test_doctor_supports_machine_readable_output(): void
    {
        $this->artisan('safernet:doctor', ['--json' => true])
            ->expectsOutputToContain('"database"')
            ->assertSuccessful();
    }
}
