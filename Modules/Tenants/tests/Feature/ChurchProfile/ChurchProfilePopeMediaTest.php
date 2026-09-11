<?php

namespace Modules\Tenants\Tests\Feature\ChurchProfile;

use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Modules\EcclesiasticalData\Tests\Support\CreatesEkklesiaTestUser;
use Modules\Tenants\Models\PopeDetails;
use Modules\Tenants\Tests\Support\MediaSecurityFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchProfilePopeMediaTest extends TestCase
{
    use CreatesEkklesiaTestUser;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    #[Test]
    public function it_uploads_pope_image_to_private_platform_path(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $response = $this->postJson('/api/church-profile/pope/upload-image', [
            'image' => MediaSecurityFixtures::validJpeg(200, 200),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pope_image_url', fn ($url) => is_string($url) && $url !== '');

        $pope = PopeDetails::getCurrent();
        $this->assertNotNull($pope?->pope_image_path);
        $this->assertStringContainsString('platform/pope/', $pope->pope_image_path);
        Storage::disk('local')->assertExists($pope->pope_image_path);
    }

    #[Test]
    public function it_rejects_invalid_pope_uploads(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->postJson('/api/church-profile/pope/upload-image', [
            'image' => MediaSecurityFixtures::textPlain(),
        ])->assertStatus(422);

        $this->postJson('/api/church-profile/pope/upload-image', [])
            ->assertStatus(422);
    }

    #[Test]
    public function it_deletes_pope_image(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->postJson('/api/church-profile/pope/upload-image', [
            'image' => MediaSecurityFixtures::validJpeg(200, 200),
        ])->assertOk();

        $path = PopeDetails::getCurrent()?->pope_image_path;
        $this->assertNotNull($path);

        $this->deleteJson('/api/church-profile/pope/image')->assertOk();

        Storage::disk('local')->assertMissing($path);
        $this->assertNull(PopeDetails::getCurrent()?->pope_image_path);
    }
}
