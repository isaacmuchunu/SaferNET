<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\Authentication\TotpService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OnboardingAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_temporary_password_requires_password_change_and_mfa_before_portal_access(): void
    {
        $user = User::factory()->scde()->create([
            'email' => 'new.scde@safernet.test',
            'password' => 'Temporary!234',
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->addHours(72),
            'mfa_required' => true,
        ]);

        $login = $this->postJson(route('api.v1.auth.login'), [
            'email' => ' NEW.SCDE@SAFERNET.TEST ',
            'password' => 'Temporary!234',
            'device_name' => 'React dashboard',
        ])->assertOk()->assertJsonPath('requires', 'password_change');

        $passwordToken = $login->json('token');
        $this->withToken($passwordToken)->getJson(route('api.v1.dashboard'))->assertForbidden();

        $password = $this->withToken($passwordToken)->postJson(route('api.v1.auth.onboarding.password'), [
            'password' => 'Preferred!Password234',
            'password_confirmation' => 'Preferred!Password234',
            'device_name' => 'React dashboard',
        ])->assertOk()->assertJsonPath('requires', 'mfa_setup');

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('Preferred!Password234', $user->password));

        $mfaToken = $password->json('token');
        $this->assertSame(['onboarding:mfa'], $user->tokens()->sole()->abilities);
        $this->app['auth']->forgetGuards();
        $setup = $this->withToken($mfaToken)
            ->postJson(route('api.v1.auth.onboarding.mfa.setup'))
            ->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'otpauth_uri', 'issuer', 'account']]);
        $secret = str_replace(' ', '', $setup->json('data.secret'));
        $code = app(TotpService::class)->code($secret);

        $confirmed = $this->withToken($mfaToken)->postJson(route('api.v1.auth.onboarding.mfa.confirm'), [
            'code' => $code,
            'device_name' => 'React dashboard',
        ])->assertOk()->assertJsonPath('requires', 'signed_in')->assertJsonCount(8, 'recovery_codes');

        $this->app['auth']->forgetGuards();
        $this->withToken($confirmed->json('token'))->getJson(route('api.v1.dashboard'))->assertOk();
        $this->assertNotNull($user->refresh()->mfa_enabled_at);
    }

    public function test_mfa_enabled_account_requires_a_valid_second_factor_for_each_new_login(): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $user = User::factory()->cde()->create([
            'email' => 'cde.mfa@safernet.test',
            'password' => 'Preferred!Password234',
            'mfa_required' => true,
            'mfa_secret' => $secret,
            'mfa_enabled_at' => now(),
            'mfa_recovery_codes' => [],
        ]);

        $login = $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'Preferred!Password234',
            'device_name' => 'React dashboard',
        ])->assertOk()->assertJsonPath('requires', 'mfa_challenge');

        $challengeToken = $login->json('token');
        $this->withToken($challengeToken)->getJson(route('api.v1.dashboard'))->assertForbidden();
        $this->withToken($challengeToken)->postJson(route('api.v1.auth.mfa.verify'), [
            'code' => '000000',
            'device_name' => 'React dashboard',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code']);

        $verified = $this->withToken($challengeToken)->postJson(route('api.v1.auth.mfa.verify'), [
            'code' => $totp->code($secret),
            'device_name' => 'React dashboard',
        ])->assertOk()->assertJsonPath('requires', 'signed_in');

        $this->assertSame(['portal:access'], $user->tokens()->sole()->abilities);

        $this->app['auth']->forgetGuards();
        $this->withToken($verified->json('token'))->getJson(route('api.v1.dashboard'))->assertOk();
        $this->assertNotNull($user->refresh()->last_login_at);
    }

    public function test_expired_temporary_password_is_rejected(): void
    {
        User::factory()->cde()->create([
            'email' => 'expired@safernet.test',
            'password' => 'Temporary!234',
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->subMinute(),
            'mfa_required' => true,
        ]);

        $this->postJson(route('api.v1.auth.login'), [
            'email' => 'expired@safernet.test',
            'password' => 'Temporary!234',
            'device_name' => 'React dashboard',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }
}
