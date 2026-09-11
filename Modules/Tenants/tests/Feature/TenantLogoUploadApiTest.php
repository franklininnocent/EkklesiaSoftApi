<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Tests\Support\MediaSecurityFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantLogoUploadApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->tenant = Tenant::factory()->active()->create();
        $role = Role::query()->firstOrCreate(
            ['name' => Role::SUPER_ADMIN, 'tenant_id' => null],
            [
                'description' => 'Super Admin',
                'level' => Role::LEVEL_SUPER_ADMIN,
                'active' => 1,
            ]
        );
        $this->admin = User::factory()->create(['tenant_id' => null]);
        $this->admin->roles()->syncWithoutDetaching([$role->id]);
        Passport::actingAs($this->admin);
    }

    #[Test]
    public function it_uploads_tenant_logo_to_private_storage(): void
    {
        $response = $this->postJson("/api/tenant/{$this->tenant->id}/logo", [
            'logo' => MediaSecurityFixtures::validJpeg(200, 200),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.logo_full_url', fn ($url) => is_string($url) && $url !== '')
            ->assertJsonMissingPath('data.logo_url');

        $this->tenant->refresh();
        $this->assertNotNull($this->tenant->logo_url);
        $this->assertStringContainsString("tenants/{$this->tenant->id}/logos/", $this->tenant->logo_url);
        Storage::disk('local')->assertExists($this->tenant->logo_url);
    }

    #[Test]
    public function it_rejects_invalid_tenant_logo_uploads(): void
    {
        $this->postJson("/api/tenant/{$this->tenant->id}/logo", [
            'logo' => MediaSecurityFixtures::textPlain(),
        ])->assertStatus(422);
    }

    #[Test]
    public function it_replaces_existing_tenant_logo(): void
    {
        $this->postJson("/api/tenant/{$this->tenant->id}/logo", [
            'logo' => MediaSecurityFixtures::validJpeg(200, 200),
        ])->assertOk();
        $firstPath = $this->tenant->fresh()->logo_url;

        $this->postJson("/api/tenant/{$this->tenant->id}/logo", [
            'logo' => MediaSecurityFixtures::validPng(240, 240),
        ])->assertOk();
        $secondPath = $this->tenant->fresh()->logo_url;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);
    }

    #[Test]
    public function it_deletes_tenant_logo(): void
    {
        $this->postJson("/api/tenant/{$this->tenant->id}/logo", [
            'logo' => MediaSecurityFixtures::validJpeg(200, 200),
        ])->assertOk();
        $path = $this->tenant->fresh()->logo_url;

        $this->deleteJson("/api/tenant/{$this->tenant->id}/logo")->assertOk();

        Storage::disk('local')->assertMissing($path);
        $this->assertNull($this->tenant->fresh()->logo_url);
    }
}
