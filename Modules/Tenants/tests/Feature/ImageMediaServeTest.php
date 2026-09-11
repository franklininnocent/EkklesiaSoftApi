<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\Media\ImageMediaPolicy;
use Modules\Tenants\Services\Media\ImageMediaService;
use Modules\Tenants\Services\Media\ImageMediaToken;
use Modules\Tenants\Services\Media\ImageMediaUrlSigner;
use Modules\Tenants\Tests\Support\MediaSecurityFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImageMediaServeTest extends TestCase
{
    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->tenant = Tenant::factory()->active()->create();
        $this->user = User::factory()->tenantUser($this->tenant->id)->create();

        Passport::actingAs($this->user);
    }

    #[Test]
    public function it_serves_authorized_private_media_with_valid_token(): void
    {
        $service = app(ImageMediaService::class);
        $stored = $service->store(
            MediaSecurityFixtures::validJpeg(),
            $this->tenant->id,
            ImageMediaPolicy::CATEGORY_USERS
        );

        $signer = app(ImageMediaUrlSigner::class);
        $url = $signer->displayUrl(
            $stored->storageKey,
            $this->tenant->id,
            fn (): bool => true
        );

        $this->assertNotNull($url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    #[Test]
    public function it_denies_expired_media_token(): void
    {
        $service = app(ImageMediaService::class);
        $stored = $service->store(
            MediaSecurityFixtures::validJpeg(),
            $this->tenant->id,
            ImageMediaPolicy::CATEGORY_USERS
        );

        $token = ImageMediaToken::encode(
            $this->tenant->id,
            $stored->storageKey,
            ImageMediaToken::VARIANT_DISPLAY,
            now()->subMinute()->timestamp
        );

        $url = URL::temporarySignedRoute(
            'api.tenants.media.serve',
            now()->addMinutes(5),
            ['token' => $token]
        );

        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function thumb_token_serves_thumb_variant_not_display_bytes(): void
    {
        $service = app(ImageMediaService::class);
        $stored = $service->store(
            MediaSecurityFixtures::validJpeg(600, 400),
            $this->tenant->id,
            ImageMediaPolicy::CATEGORY_USERS
        );

        $token = ImageMediaToken::encode(
            $this->tenant->id,
            $stored->storageKey,
            ImageMediaToken::VARIANT_THUMB,
            now()->addMinutes(10)->timestamp
        );

        $url = URL::temporarySignedRoute(
            'api.tenants.media.serve',
            now()->addMinutes(10),
            ['token' => $token]
        );

        $response = $this->get($url);
        $response->assertOk();

        $body = $response->streamedContent();

        $this->assertSame(
            Storage::disk('local')->get($stored->thumbStorageKey),
            $body
        );
        $this->assertNotSame(
            Storage::disk('local')->get($stored->storageKey),
            $body
        );
    }

    #[Test]
    public function signer_returns_null_for_unauthorized_media_even_when_file_exists(): void
    {
        $service = app(ImageMediaService::class);
        $stored = $service->store(
            MediaSecurityFixtures::validJpeg(),
            $this->tenant->id,
            ImageMediaPolicy::CATEGORY_USERS
        );

        $signer = app(ImageMediaUrlSigner::class);
        $url = $signer->displayUrl(
            $stored->storageKey,
            $this->tenant->id,
            fn (): bool => false
        );

        $this->assertNull($url);
    }

    #[Test]
    public function it_denies_cross_tenant_media_serve(): void
    {
        $otherTenant = Tenant::factory()->active()->create();
        $service = app(ImageMediaService::class);
        $stored = $service->store(
            MediaSecurityFixtures::validJpeg(),
            $otherTenant->id,
            ImageMediaPolicy::CATEGORY_USERS
        );

        $token = ImageMediaToken::encode(
            $otherTenant->id,
            $stored->storageKey,
            ImageMediaToken::VARIANT_DISPLAY,
            now()->addMinutes(10)->timestamp
        );

        $url = URL::temporarySignedRoute(
            'api.tenants.media.serve',
            now()->addMinutes(10),
            ['token' => $token]
        );

        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function it_sets_private_cache_control_shorter_than_token_ttl(): void
    {
        $service = app(ImageMediaService::class);
        $stored = $service->store(
            MediaSecurityFixtures::validJpeg(),
            $this->tenant->id,
            ImageMediaPolicy::CATEGORY_USERS
        );

        $signer = app(ImageMediaUrlSigner::class);
        $url = $signer->displayUrl(
            $stored->storageKey,
            $this->tenant->id,
            fn (): bool => true
        );

        $this->assertNotNull($url);

        $maxAge = (int) config('tenants.media.cache_max_age_seconds', 300);
        $displayTtlSeconds = (int) config('tenants.media.display_ttl_minutes', 15) * 60;

        $response = $this->get($url)->assertOk();
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));

        $this->assertLessThan($displayTtlSeconds, $maxAge);
        $this->assertLessThan((int) config('tenants.media.thumb_ttl_minutes', 10) * 60, $maxAge);
    }
}
