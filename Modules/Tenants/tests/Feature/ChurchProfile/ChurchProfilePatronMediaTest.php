<?php

namespace Modules\Tenants\Tests\Feature\ChurchProfile;

use Illuminate\Support\Facades\Storage;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Testing\ChurchProfileCertificationTestCase;
use Modules\Tenants\Tests\Support\MediaSecurityFixtures;
use PHPUnit\Framework\Attributes\Test;

class ChurchProfilePatronMediaTest extends ChurchProfileCertificationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    #[Test]
    public function it_should_upload_patron_image_under_tenant_patron_path(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);

        $response = $this->postJson('/api/church-profile/upload-patron-image', [
            'image' => MediaSecurityFixtures::validJpeg(200, 200),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.patron_image_url', fn ($url) => is_string($url) && $url !== '')
            ->assertJsonMissingPath('data.patron_image_path');

        $profile = ChurchProfile::query()->where('tenant_id', $ctx['tenant']->id)->first();
        $this->assertNotNull($profile?->patron_image_path);
        $this->assertStringContainsString("tenants/{$ctx['tenant']->id}/patron/", $profile->patron_image_path);
        Storage::disk('local')->assertExists($profile->patron_image_path);
    }

    #[Test]
    public function it_should_replace_existing_patron_image_on_reupload(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $this->postJson('/api/church-profile/upload-patron-image', [
            'image' => MediaSecurityFixtures::validJpeg(200, 200),
        ])->assertOk();
        $firstPath = ChurchProfile::query()->where('tenant_id', $ctx['tenant']->id)->value('patron_image_path');

        $this->postJson('/api/church-profile/upload-patron-image', [
            'image' => MediaSecurityFixtures::validPng(220, 220),
        ])->assertOk();
        $secondPath = ChurchProfile::query()->where('tenant_id', $ctx['tenant']->id)->value('patron_image_path');

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);
    }

    #[Test]
    public function it_should_reject_invalid_patron_uploads(): void
    {
        $this->actingAsTenantWith(['church.settings.edit']);

        $this->postJson('/api/church-profile/upload-patron-image', [
            'image' => MediaSecurityFixtures::textPlain(),
        ])->assertStatus(422);

        $this->postJson('/api/church-profile/upload-patron-image', [])
            ->assertStatus(422);
    }

    #[Test]
    public function it_should_delete_patron_image_when_editor_has_delete_permission(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit', 'church.settings.delete']);

        $this->postJson('/api/church-profile/upload-patron-image', [
            'image' => MediaSecurityFixtures::validJpeg(200, 200),
        ])->assertOk();
        $path = ChurchProfile::query()->where('tenant_id', $ctx['tenant']->id)->value('patron_image_path');

        $this->deleteJson('/api/church-profile/patron-image')->assertOk();
        Storage::disk('local')->assertMissing($path);

        $this->assertDatabaseHas('church_profiles', [
            'tenant_id' => $ctx['tenant']->id,
            'patron_image_path' => null,
        ]);
    }

    #[Test]
    public function it_should_not_delete_foreign_tenant_patron_file_when_path_is_tampered(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit', 'church.settings.delete']);
        $otherTenant = $this->makeOperationalTenant();
        $foreignPath = 'tenants/'.$otherTenant->id.'/patron/foreign.webp';
        Storage::disk('local')->put($foreignPath, 'foreign');

        ChurchProfile::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'patron_image_path' => $foreignPath,
        ]);

        $this->deleteJson('/api/church-profile/patron-image')->assertOk();

        Storage::disk('local')->assertExists($foreignPath);
        $this->assertDatabaseHas('church_profiles', [
            'tenant_id' => $ctx['tenant']->id,
            'patron_image_path' => null,
        ]);
    }
}
