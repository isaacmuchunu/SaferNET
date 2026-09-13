<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticatedSessionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_can_list_only_their_own_sessions(): void
    {
        $user = User::factory()->cde()->create();
        $other = User::factory()->cde()->create();
        $current = $user->createToken('Current browser', ['portal:access'])->accessToken;
        $user->createToken('Other browser', ['portal:access']);
        $other->createToken('Hidden browser', ['portal:access']);

        Sanctum::actingAs($user, ['portal:access']);
        $user->withAccessToken($current);

        $this->getJson(route('api.v1.auth.sessions.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['name' => 'Hidden browser']);
    }

    public function test_user_can_revoke_another_session_without_revoking_current_session(): void
    {
        $user = User::factory()->cde()->create();
        $current = $user->createToken('Current browser', ['portal:access'])->accessToken;
        $other = $user->createToken('Other browser', ['portal:access'])->accessToken;

        Sanctum::actingAs($user, ['portal:access']);
        $user->withAccessToken($current);

        $this->deleteJson(route('api.v1.auth.sessions.destroy', $other->id))->assertNoContent();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->id]);
    }

    public function test_user_cannot_revoke_another_users_session(): void
    {
        $user = User::factory()->cde()->create();
        $otherToken = User::factory()->cde()->create()->createToken('Hidden', ['portal:access'])->accessToken;

        Sanctum::actingAs($user, ['portal:access']);

        $this->deleteJson(route('api.v1.auth.sessions.destroy', $otherToken->id))->assertNotFound();
    }
}
