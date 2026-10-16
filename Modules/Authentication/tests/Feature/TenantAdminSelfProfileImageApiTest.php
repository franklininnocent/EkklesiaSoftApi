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
use Tests\Concerns\InteractsWithTenantContext;
use Tests\TestCase;

class TenantAdminSelfProfileImageApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithTenantContext;
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
        $this->bindTenantContext($this->admin);
    }

    private function createStaffUser(): User
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'active' => 1,
        ]);

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
    public function tenant_admin_can_upload_own_profile_image(): void
    {
        $this->authenticateAdmin();

        $response = $this->postJson('/api/auth/profile-image', [
            'profile_image' => UploadedFile::fake()->image('self.jpg', 400, 400),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $this->admin->id)
            ->assertJsonPath('data.profile_image_full_url', fn ($url) => is_string($url) && $url !== '');

        $this->admin->refresh();
        $this->assertNotNull($this->admin->profile_image_path);
        $this->assertStringContainsString("tenants/{$this->tenant->id}/users/", $this->admin->profile_image_path);
        Storage::disk('local')->assertExists($this->admin->profile_image_path);

        $this->getJson('/api/auth/get-user')
            ->assertOk()
            ->assertJsonPath('data.profile_image_full_url', fn ($url) => is_string($url) && $url !== '');
    }

    #[Test]
    public function tenant_admin_can_replace_and_remove_own_profile_image(): void
    {
        $this->authenticateAdmin();

        $this->postJson('/api/auth/profile-image', [
            'profile_image' => UploadedFile::fake()->image('first.jpg', 200, 200),
        ])->assertOk();
        $firstPath = $this->admin->fresh()->profile_image_path;

        $this->postJson('/api/auth/profile-image', [
            'profile_image' => UploadedFile::fake()->image('second.jpg', 200, 200),
        ])->assertOk();
        $secondPath = $this->admin->fresh()->profile_image_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);

        $this->deleteJson('/api/auth/profile-image')
            ->assertOk()
            ->assertJsonPath('data.profile_image_full_url', null);

        Storage::disk('local')->assertMissing($secondPath);
        $this->assertNull($this->admin->fresh()->profile_image_path);
    }

    #[Test]
    public function extra_fields_on_self_image_upload_are_ignored(): void
    {
        $this->authenticateAdmin();
        $originalName = $this->admin->name;
        $originalEmail = $this->admin->email;

        $this->postJson('/api/auth/profile-image', [
            'profile_image' => UploadedFile::fake()->image('self.jpg', 200, 200),
            'name' => 'Hacked Name',
            'email' => 'hacked@example.com',
            'password' => 'new-password',
            'user_id' => 99999,
        ])->assertOk();

        $this->admin->refresh();
        $this->assertSame($originalName, $this->admin->name);
        $this->assertSame($originalEmail, $this->admin->email);
        $this->assertNotNull($this->admin->profile_image_path);
    }

    #[Test]
    public function staff_user_cannot_use_self_profile_image_endpoint(): void
    {
        $staff = $this->createStaffUser();
        Passport::actingAs($staff);
        $this->bindTenantContext($staff);

        $this->postJson('/api/auth/profile-image', [
            'profile_image' => UploadedFile::fake()->image('staff.jpg', 200, 200),
        ])->assertForbidden();

        $this->deleteJson('/api/auth/profile-image')
            ->assertForbidden();
    }

    #[Test]
    public function tenant_admin_still_blocked_from_users_self_image_endpoint(): void
    {
        $this->authenticateAdmin();

        $this->postJson("/api/users/{$this->admin->id}/profile-image", [
            'profile_image' => UploadedFile::fake()->image('self.jpg', 200, 200),
        ])->assertForbidden();

        $this->deleteJson("/api/users/{$this->admin->id}/profile-image")
            ->assertForbidden();
    }

    #[Test]
    public function tenant_admin_cannot_update_other_users_image_via_self_endpoint(): void
    {
        $this->authenticateAdmin();
        $target = $this->createStaffUser();

        $this->postJson('/api/auth/profile-image', [
            'profile_image' => UploadedFile::fake()->image('self.jpg', 200, 200),
            'user_id' => $target->id,
        ])->assertOk();

        $this->assertNull($target->fresh()->profile_image_path);
        $this->assertNotNull($this->admin->fresh()->profile_image_path);
    }

    #[Test]
    public function invalid_self_profile_image_upload_is_rejected_and_preserves_existing_image(): void
    {
        $this->authenticateAdmin();

        $this->postJson('/api/auth/profile-image', [
            'profile_image' => UploadedFile::fake()->image('valid.jpg', 200, 200),
        ])->assertOk();
        $existingPath = $this->admin->fresh()->profile_image_path;

        $this->postJson('/api/auth/profile-image', [
            'profile_image' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertStatus(422);

        $this->assertSame($existingPath, $this->admin->fresh()->profile_image_path);
        Storage::disk('local')->assertExists($existingPath);
    }

}
