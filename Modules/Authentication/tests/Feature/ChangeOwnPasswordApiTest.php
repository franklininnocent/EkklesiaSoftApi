<?php

namespace Modules\Authentication\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChangeOwnPasswordApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPasswordGrantClient();
    }

    private function seedPasswordGrantClient(): void
    {
        if (\Illuminate\Support\Facades\DB::table('oauth_clients')->exists()) {
            return;
        }

        \Illuminate\Support\Facades\DB::table('oauth_clients')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'owner_type' => null,
            'owner_id' => null,
            'name' => 'Password Grant Client',
            'secret' => \Illuminate\Support\Str::random(40),
            'provider' => 'users',
            'redirect_uris' => json_encode([]),
            'grant_types' => json_encode(['password', 'refresh_token']),
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function super_admin_can_change_own_password_via_profile_endpoint(): void
    {
        $superRole = Role::create([
            'name' => Role::SUPER_ADMIN,
            'description' => Role::SUPER_ADMIN,
            'level' => Role::LEVEL_SUPER_ADMIN,
            'active' => 1,
            'tenant_id' => null,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_PLATFORM,
            'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
        ]);

        $superAdmin = User::factory()->create([
            'email' => 'superadmin-change-password@test.local',
            'password' => Hash::make('Current*123'),
            'tenant_id' => null,
            'role_id' => $superRole->id,
            'active' => 1,
        ]);
        $superAdmin->syncRoles([$superRole->id]);

        Passport::actingAs($superAdmin);

        $response = $this->postJson('/api/auth/password/change', [
            'current_password' => 'Current*123',
            'password' => 'NewPass*456',
            'password_confirmation' => 'NewPass*456',
        ]);

        if ($response->status() !== 200) {
            fwrite(STDERR, json_encode($response->json()).PHP_EOL);
        }

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['access_token', 'refresh_token', 'expiry_time', 'token_type'],
            ]);

        $superAdmin->refresh();
        $this->assertTrue(Hash::check('NewPass*456', $superAdmin->password));
        $this->assertFalse((bool) $superAdmin->force_password_change);
    }
}
