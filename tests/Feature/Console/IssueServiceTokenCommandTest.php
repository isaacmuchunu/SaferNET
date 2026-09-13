<?php

namespace Tests\Feature\Console;

use App\Enums\UserRole;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class IssueServiceTokenCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_issues_an_institution_bound_telemetry_token(): void
    {
        $institution = Institution::factory()->create();

        $this->artisan('safernet:issue-service-token', [
            'institution' => $institution->nemis_code,
            '--name' => 'lab-gateway',
        ])->assertSuccessful();

        $identity = User::query()->where('role', UserRole::Service)->sole();
        $token = $identity->tokens()->sole();

        $this->assertSame($institution->id, $identity->institution_id);
        $this->assertSame($institution->subcounty_id, $identity->subcounty_id);
        $this->assertSame(['telemetry:write'], $token->abilities);
    }
}
