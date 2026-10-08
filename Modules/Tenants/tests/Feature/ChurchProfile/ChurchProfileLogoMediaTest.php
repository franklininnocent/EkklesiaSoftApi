<?php

namespace Modules\Tenants\Tests\Feature\ChurchProfile;

use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Testing\ChurchProfileCertificationTestCase;
use Modules\Tenants\Tests\Support\MediaSecurityFixtures;
use PHPUnit\Framework\Attributes\Test;

class ChurchProfileLogoMediaTest extends ChurchProfileCertificationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    #[Test]
    public function tenant_admin_can_upload_and_replace_the_parish_logo(): void
    {
        $ctx = $this->actingAsParishAdmin();

        $response = $this->postJson('/api/tenant/church-profile/logo', [
            'logo' => MediaSecurityFixtures::validJpeg(200, 200),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.logo_full_url', fn ($url) => is_string($url) && str_contains($url, '/api/tenant/media/serve'))
            ->assertJsonMissingPath('data.logo_url');

        $tenant = Tenant::query()->findOrFail($ctx['tenant']->id);
        $this->assertNotNull($tenant->logo_url);
        $this->assertStringContainsString("tenants/{$tenant->id}/logos/", $tenant->logo_url);
        Storage::disk('local')->assertExists($tenant->logo_url);
        $firstPath = $tenant->logo_url;

        $this->postJson('/api/tenant/church-profile/logo', [
            'logo' => MediaSecurityFixtures::validPng(240, 240),
        ])->assertOk();

        $tenant->refresh();
        $this->assertNotSame($firstPath, $tenant->logo_url);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($tenant->logo_url);
    }

    #[Test]
    public function parish_viewer_cannot_upload_or_delete_the_logo(): void
    {
        $this->actingAsTenantWith(['donations.view']);

        $this->postJson('/api/tenant/church-profile/logo', [
            'logo' => MediaSecurityFixtures::validJpeg(200, 200),
        ])->assertForbidden();

        $this->deleteJson('/api/tenant/church-profile/logo')->assertForbidden();
    }

    #[Test]
    public function tenant_admin_can_remove_the_parish_logo(): void
    {
        $ctx = $this->actingAsParishAdmin();

        $this->postJson('/api/tenant/church-profile/logo', [
            'logo' => MediaSecurityFixtures::validJpeg(200, 200),
        ])->assertOk();

        $path = Tenant::query()->findOrFail($ctx['tenant']->id)->logo_url;
        $this->assertNotNull($path);

        $this->deleteJson('/api/tenant/church-profile/logo')->assertOk();

        $this->assertNull(Tenant::query()->findOrFail($ctx['tenant']->id)->logo_url);
        Storage::disk('local')->assertMissing($path);
    }

    /**
     * @return array{tenant: Tenant, user: mixed, role: mixed}
     */
    private function actingAsParishAdmin(): array
    {
        $context = $this->makeTenantPersona(
            null,
            Role::TENANT_ADMINISTRATOR,
            ['church.settings.edit', 'church.settings.delete'],
            [
                'is_custom' => false,
                'level' => 2,
            ]
        );
        Passport::actingAs($context['user']);

        return $context;
    }
}
