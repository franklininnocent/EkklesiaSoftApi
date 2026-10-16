<?php

namespace Tests\Feature;

use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Tests\TestCase;

class BearerTokenAuthSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_user_accepts_bearer_token_from_token_service(): void
    {
        $this->seed(\Database\Seeders\OAuthClientSeeder::class);

        $user = User::factory()->create(['active' => 1]);
        $token = app(TokenService::class)->createTokens($user, null, [], null, 'test')['access_token_string'];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/get-user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }
}
