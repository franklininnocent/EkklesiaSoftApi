<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
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

class MediaUploadRateLimitTest extends TestCase
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

        config([
            'tenants.api.rate_limit.enabled' => true,
            'tenants.api.rate_limit.buckets.uploads' => [
                'max_attempts' => 2,
                'decay_seconds' => 60,
            ],
            'tenants.api.rate_limit.buckets.media_serve' => [
                'max_attempts' => 2,
                'decay_seconds' => 60,
            ],
        ]);

        $this->tenant = $this->makeOperationalTenant();
        $context = $this->makeTenantPersona(
            $this->tenant,
            'Upload Limiter',
            array_merge($this->parishAdminPermissionNames(), ['families.view', 'families.edit']),
            ['is_custom' => true, 'level' => 2]
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
        RateLimiter::clear($this->uploadRateLimitKey());
    }

    #[Test]
    public function it_returns_429_when_upload_bucket_is_exceeded(): void
    {
        $payload = ['profile_image' => MediaSecurityFixtures::validJpeg(200, 200)];

        $this->postJson("/api/families/{$this->family->id}/profile-image", $payload)->assertOk();
        $this->postJson("/api/families/{$this->family->id}/profile-image", $payload)->assertOk();

        $this->postJson("/api/families/{$this->family->id}/profile-image", $payload)
            ->assertStatus(429)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_returns_429_when_media_serve_bucket_is_exceeded(): void
    {
        $upload = $this->postJson("/api/families/{$this->family->id}/profile-image", [
            'profile_image' => MediaSecurityFixtures::validJpeg(200, 200),
        ])->assertOk();

        $url = $upload->json('data.profile_image_full_url');
        $this->assertIsString($url);

        $this->get($url)->assertOk();
        $this->get($url)->assertOk();
        $this->get($url)->assertStatus(429);
    }

    private function uploadRateLimitKey(): string
    {
        return 'api:uploads:t'.$this->tenant->id.':u'.$this->user->id.':127.0.0.1';
    }
}
