<?php

namespace Modules\Tenants\Tests\Feature\ChurchProfile;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Testing\ChurchProfileCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class ChurchProfilePatronMediaTest extends ChurchProfileCertificationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    #[Test]
    public function it_should_upload_patron_image_under_tenant_patron_path(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);

        $response = $this->postJson('/api/church-profile/upload-patron-image', [
            'image' => UploadedFile::fake()->image('patron.jpg', 400, 400),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.patron_image_path', fn ($path) => is_string($path)
                && str_contains($path, 'tenants/'.$ctx['tenant']->id.'/patron/'));

        $path = $response->json('data.patron_image_path');
        Storage::disk('public')->assertExists($path);

        $this->assertDatabaseHas('church_profiles', [
            'tenant_id' => $ctx['tenant']->id,
            'patron_image_path' => $path,
        ]);
    }

    #[Test]
    public function it_should_replace_existing_patron_image_on_reupload(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $first = $this->postJson('/api/church-profile/upload-patron-image', [
            'image' => UploadedFile::fake()->image('first.jpg'),
        ])->assertOk();
        $firstPath = $first->json('data.patron_image_path');

        $second = $this->postJson('/api/church-profile/upload-patron-image', [
            'image' => UploadedFile::fake()->image('second.jpg'),
        ])->assertOk();
        $secondPath = $second->json('data.patron_image_path');

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    #[Test]
    public function it_should_reject_invalid_patron_uploads(): void
    {
        $this->actingAsTenantWith(['church.settings.edit']);

        $this->postJson('/api/church-profile/upload-patron-image', [
            'image' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertStatus(422);

        $this->postJson('/api/church-profile/upload-patron-image', [])
            ->assertStatus(422);
    }

    #[Test]
    public function it_should_delete_patron_image_when_editor_has_delete_permission(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit', 'church.settings.delete']);

        $upload = $this->postJson('/api/church-profile/upload-patron-image', [
            'image' => UploadedFile::fake()->image('patron.jpg'),
        ])->assertOk();
        $path = $upload->json('data.patron_image_path');

        $this->deleteJson('/api/church-profile/patron-image')->assertOk();
        Storage::disk('public')->assertMissing($path);

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
        $foreignPath = 'tenants/'.$otherTenant->id.'/patron/foreign.jpg';
        Storage::disk('public')->put($foreignPath, 'foreign');

        ChurchProfile::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'patron_image_path' => $foreignPath,
        ]);

        $this->deleteJson('/api/church-profile/patron-image')->assertOk();

        Storage::disk('public')->assertExists($foreignPath);
        $this->assertDatabaseHas('church_profiles', [
            'tenant_id' => $ctx['tenant']->id,
            'patron_image_path' => null,
        ]);
    }
}
