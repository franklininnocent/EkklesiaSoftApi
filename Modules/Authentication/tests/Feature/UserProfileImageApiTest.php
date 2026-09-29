<?php

namespace Modules\Authentication\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class UserProfileImageApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->tenant = $this->makeOperationalTenant();

        $adminBundle = $this->makeTenantPersona(
            $this->tenant,
            Role::TENANT_ADMINISTRATOR,
            array_merge($this->parishAdminPermissionNames(), ['users.create', 'users.update', 'users.delete']),
            [
                'is_custom' => false,
                'level' => 1,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );
        $this->admin = $adminBundle['user'];
    }

    private function authenticateAdmin(): void
    {
        Passport::actingAs($this->admin);
    }

    private function createTenantUser(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'active' => 1,
        ], $overrides));

        $staffRole = Role::query()->firstOrCreate(
            [
                'name' => 'Staff',
                'tenant_id' => $this->tenant->id,
            ],
            [
                'description' => 'Staff',
                'level' => 3,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_TENANT,
            ]
        );

        $user->syncRoles([$staffRole->id]);

        return $user->fresh(['roles']);
    }

    #[Test]
    public function it_uploads_user_profile_image_under_tenant_path(): void
    {
        $this->authenticateAdmin();
        $target = $this->createTenantUser(['name' => 'Photo User']);

        $response = $this->postJson("/api/users/{$target->id}/profile-image", [
            'profile_image' => UploadedFile::fake()->image('profile.jpg', 400, 400),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.profile_image_full_url', fn ($url) => is_string($url) && $url !== '');

        $target->refresh();
        $this->assertNotNull($target->profile_image_path);
        $this->assertStringContainsString("tenants/{$this->tenant->id}/users/", $target->profile_image_path);
        Storage::disk('local')->assertExists($target->profile_image_path);
        $this->assertStringEndsWith('.webp', $target->profile_image_path);
    }

    #[Test]
    public function it_rejects_invalid_profile_image_uploads(): void
    {
        $this->authenticateAdmin();
        $target = $this->createTenantUser();

        $this->postJson("/api/users/{$target->id}/profile-image", [
            'profile_image' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertStatus(422);

        $this->postJson("/api/users/{$target->id}/profile-image", [])
            ->assertStatus(422);
    }

    #[Test]
    public function it_replaces_existing_profile_image(): void
    {
        $this->authenticateAdmin();
        $target = $this->createTenantUser();

        $first = $this->postJson("/api/users/{$target->id}/profile-image", [
            'profile_image' => UploadedFile::fake()->image('first.jpg', 200, 200),
        ])->assertOk();
        $firstPath = $target->fresh()->profile_image_path;

        $second = $this->postJson("/api/users/{$target->id}/profile-image", [
            'profile_image' => UploadedFile::fake()->image('second.jpg', 200, 200),
        ])->assertOk();
        $secondPath = $target->fresh()->profile_image_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);
    }

    #[Test]
    public function it_removes_profile_image(): void
    {
        $this->authenticateAdmin();
        $target = $this->createTenantUser();

        $upload = $this->postJson("/api/users/{$target->id}/profile-image", [
            'profile_image' => UploadedFile::fake()->image('profile.jpg', 200, 200),
        ])->assertOk();
        $path = $target->fresh()->profile_image_path;

        $this->deleteJson("/api/users/{$target->id}/profile-image")
            ->assertOk()
            ->assertJsonPath('data.profile_image_full_url', null);

        Storage::disk('local')->assertMissing($path);
        $this->assertNull($target->fresh()->profile_image_path);
    }

    #[Test]
    public function it_blocks_self_profile_image_changes(): void
    {
        Passport::actingAs($this->admin);

        $this->postJson("/api/users/{$this->admin->id}/profile-image", [
            'profile_image' => UploadedFile::fake()->image('self.jpg', 200, 200),
        ])->assertForbidden();

        $this->deleteJson("/api/users/{$this->admin->id}/profile-image")
            ->assertForbidden();
    }

    #[Test]
    public function it_prevents_cross_tenant_profile_image_access(): void
    {
        $this->authenticateAdmin();

        $otherTenant = $this->makeOperationalTenant();
        $foreignUser = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'active' => 1,
        ]);

        $this->postJson("/api/users/{$foreignUser->id}/profile-image", [
            'profile_image' => UploadedFile::fake()->image('foreign.jpg', 200, 200),
        ])->assertNotFound();

        $this->deleteJson("/api/users/{$foreignUser->id}/profile-image")
            ->assertNotFound();
    }

    #[Test]
    public function json_user_update_does_not_clear_profile_image(): void
    {
        $this->authenticateAdmin();
        $target = $this->createTenantUser(['name' => 'Before Photo']);

        $this->postJson("/api/users/{$target->id}/profile-image", [
            'profile_image' => UploadedFile::fake()->image('keep.jpg', 200, 200),
        ])->assertOk();

        $path = $target->fresh()->profile_image_path;
        $this->assertNotNull($path);

        $this->putJson("/api/users/{$target->id}", [
            'name' => 'After Rename',
            'email' => $target->email,
            'role_ids' => $target->roles->pluck('id')->all(),
            'active' => 1,
        ])->assertOk();

        $target->refresh();
        $this->assertSame($path, $target->profile_image_path);
        $this->assertSame('After Rename', $target->name);
    }

    #[Test]
    public function deleting_user_removes_profile_image_file(): void
    {
        $this->authenticateAdmin();
        $target = $this->createTenantUser(['is_primary_admin' => false]);

        $upload = $this->postJson("/api/users/{$target->id}/profile-image", [
            'profile_image' => UploadedFile::fake()->image('delete-me.jpg', 200, 200),
        ])->assertOk();
        $path = $target->fresh()->profile_image_path;

        $this->deleteJson("/api/users/{$target->id}")
            ->assertOk();

        Storage::disk('local')->assertMissing($path);
    }
}
