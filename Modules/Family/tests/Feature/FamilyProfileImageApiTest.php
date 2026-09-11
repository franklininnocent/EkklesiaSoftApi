<?php

namespace Modules\Family\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\Family\Models\Family;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Tests\Support\MediaSecurityFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\Concerns\InteractsWithTenantContext;
use Tests\TestCase;

class FamilyProfileImageApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithTenantContext;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->tenant = $this->makeOperationalTenant();
        $context = $this->makeTenantPersona(
            $this->tenant,
            'Family Editor',
            array_merge($this->parishAdminPermissionNames(), ['families.view', 'families.edit']),
            [
                'is_custom' => true,
                'level' => 2,
                'role_classification' => \Modules\Authentication\Models\Role::CLASSIFICATION_CUSTOM,
            ]
        );
        $this->user = $context['user'];
        $this->bindTenantContext($this->user);

        $bcc = BCC::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $bcc->id,
            'created_by' => $this->user->id,
        ]);

        Passport::actingAs($this->user);
    }

    #[Test]
    public function it_uploads_family_profile_image_under_private_tenant_path(): void
    {
        $response = $this->postJson("/api/families/{$this->family->id}/profile-image", [
            'profile_image' => MediaSecurityFixtures::validJpeg(200, 200),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.profile_image_full_url', fn ($url) => is_string($url) && $url !== '');

        $this->family->refresh();
        $this->assertNotNull($this->family->profile_image_url);
        $this->assertStringContainsString("tenants/{$this->tenant->id}/families/", $this->family->profile_image_url);
        Storage::disk('local')->assertExists($this->family->profile_image_url);
    }

    #[Test]
    public function it_uploads_head_profile_image(): void
    {
        $response = $this->postJson("/api/families/{$this->family->id}/head-profile-image", [
            'head_profile_image' => MediaSecurityFixtures::validJpeg(200, 200),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.head_profile_image_full_url', fn ($url) => is_string($url) && $url !== '');

        $this->family->refresh();
        Storage::disk('local')->assertExists($this->family->head_profile_image_url);
    }

    #[Test]
    public function it_rejects_invalid_family_profile_uploads(): void
    {
        $this->postJson("/api/families/{$this->family->id}/profile-image", [
            'profile_image' => MediaSecurityFixtures::textPlain(),
        ])->assertStatus(422);

        $this->postJson("/api/families/{$this->family->id}/profile-image", [])
            ->assertStatus(422);
    }

    #[Test]
    public function it_rejects_too_small_family_profile_images(): void
    {
        $this->postJson("/api/families/{$this->family->id}/profile-image", [
            'profile_image' => UploadedFile::fake()->image('tiny.jpg', 32, 32),
        ])->assertStatus(422);
    }

    #[Test]
    public function it_replaces_existing_family_profile_image(): void
    {
        $this->postJson("/api/families/{$this->family->id}/profile-image", [
            'profile_image' => MediaSecurityFixtures::validJpeg(200, 200),
        ])->assertOk();
        $firstPath = $this->family->fresh()->profile_image_url;

        $this->postJson("/api/families/{$this->family->id}/profile-image", [
            'profile_image' => MediaSecurityFixtures::validPng(220, 220),
        ])->assertOk();
        $secondPath = $this->family->fresh()->profile_image_url;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);
    }

    #[Test]
    public function it_deletes_family_profile_image(): void
    {
        $this->postJson("/api/families/{$this->family->id}/profile-image", [
            'profile_image' => MediaSecurityFixtures::validJpeg(200, 200),
        ])->assertOk();
        $path = $this->family->fresh()->profile_image_url;

        $this->deleteJson("/api/families/{$this->family->id}/profile-image")
            ->assertOk()
            ->assertJsonPath('data.profile_image_full_url', null);

        Storage::disk('local')->assertMissing($path);
        $this->assertNull($this->family->fresh()->profile_image_url);
    }

    #[Test]
    public function unauthenticated_users_cannot_upload_family_profile_images(): void
    {
        Passport::actingAs($this->user);
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/families/{$this->family->id}/profile-image", [
            'profile_image' => MediaSecurityFixtures::validJpeg(200, 200),
        ])->assertUnauthorized();
    }

    #[Test]
    public function it_records_audit_log_when_profile_image_is_uploaded(): void
    {
        $this->postJson("/api/families/{$this->family->id}/profile-image", [
            'profile_image' => MediaSecurityFixtures::validJpeg(200, 200),
        ])->assertOk();

        $this->assertDatabaseHas('family_audit_logs', [
            'target_id' => $this->family->id,
            'event' => 'family.profile_image.uploaded',
        ]);
    }

    #[Test]
    public function it_denies_upload_to_family_in_another_tenant(): void
    {
        $otherTenant = $this->makeOperationalTenant();
        $otherBcc = BCC::factory()->create(['tenant_id' => $otherTenant->id]);
        $foreignFamily = Family::factory()->create([
            'tenant_id' => $otherTenant->id,
            'bcc_id' => $otherBcc->id,
        ]);

        $this->postJson("/api/families/{$foreignFamily->id}/profile-image", [
            'profile_image' => MediaSecurityFixtures::validJpeg(200, 200),
        ])->assertNotFound();
    }
}
