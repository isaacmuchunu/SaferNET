<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_active_administrator_receives_api_token(): void
    {
        $user = User::factory()->cde()->create(['email' => 'cde@safernet.test']);

        $response = $this->postJson(route('api.v1.auth.login'), [
            'email' => 'cde@safernet.test',
            'password' => 'password',
            'device_name' => 'React dashboard',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.role', 'cde')
            ->assertJsonStructure(['data' => ['id', 'name', 'email', 'role'], 'token']);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'React dashboard']);
        $this->assertSame(['portal:access'], $user->tokens()->sole()->abilities);
    }

    public function test_returns_422_for_invalid_credentials(): void
    {
        User::factory()->cde()->create(['email' => 'cde@safernet.test']);

        $response = $this->postJson(route('api.v1.auth.login'), [
            'email' => 'cde@safernet.test',
            'password' => 'incorrect',
            'device_name' => 'React dashboard',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_returns_422_for_suspended_account(): void
    {
        User::factory()->cde()->create(['email' => 'cde@safernet.test', 'status' => 'suspended']);

        $response = $this->postJson(route('api.v1.auth.login'), [
            'email' => 'cde@safernet.test',
            'password' => 'password',
            'device_name' => 'React dashboard',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }
}
