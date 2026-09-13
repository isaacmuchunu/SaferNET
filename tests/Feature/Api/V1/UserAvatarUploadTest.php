<?php

namespace Tests\Feature\Api\V1;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAvatarUploadTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_an_officer_can_upload_their_profile_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->hoi()->create();
        Sanctum::actingAs($user, ['portal:access']);

        $response = $this->post(route('api.v1.users.update', $user), [
            '_method' => 'PUT',
            'avatar' => UploadedFile::fake()->image('officer.jpg', 480, 480),
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('data.id', $user->id);
        $path = $user->fresh()->avatar_path;

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringContainsString('/storage/avatars/', $response->json('data.avatar_url'));
    }

    public function test_an_officer_cannot_upload_an_image_for_another_school_user(): void
    {
        Storage::fake('public');
        $ownSchool = Institution::factory()->create();
        $otherSchool = Institution::factory()->create();
        $actor = User::factory()->hoi($ownSchool)->create();
        $target = User::factory()->clm($otherSchool)->create();
        Sanctum::actingAs($actor, ['portal:access']);

        $this->post(route('api.v1.users.update', $target), [
            '_method' => 'PUT',
            'avatar' => UploadedFile::fake()->image('foreign.jpg', 480, 480),
        ], ['Accept' => 'application/json'])->assertNotFound();

        $this->assertNull($target->fresh()->avatar_path);
    }
}
