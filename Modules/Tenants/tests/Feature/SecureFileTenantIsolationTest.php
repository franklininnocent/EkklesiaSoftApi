<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecureFileTenantIsolationTest extends TestCase
{
    private Tenant $tenant;

    private Tenant $otherTenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->tenant = Tenant::factory()->active()->create();
        $this->otherTenant = Tenant::factory()->active()->create();
        $this->user = User::factory()->tenantUser($this->tenant->id)->create();

        Passport::actingAs($this->user);
    }

    #[Test]
    public function it_denies_signed_url_generation_for_cross_tenant_family_path(): void
    {
        $foreignPath = 'families/'.$this->otherTenant->id.'/profiles/foreign.jpg';
        Storage::disk('public')->put($foreignPath, 'foreign');

        $this->postJson('/api/tenant/files/signed-url', [
            'path' => $foreignPath,
        ])->assertForbidden();
    }

    #[Test]
    public function it_allows_signed_url_generation_for_own_tenant_family_path(): void
    {
        $ownPath = 'families/'.$this->tenant->id.'/profiles/own.jpg';
        Storage::disk('public')->put($ownPath, 'own');

        $this->postJson('/api/tenant/files/signed-url', [
            'path' => $ownPath,
        ])->assertOk()
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_denies_serving_cross_tenant_family_file_even_with_valid_signature(): void
    {
        $foreignPath = 'families/'.$this->otherTenant->id.'/profiles/foreign.jpg';
        Storage::disk('public')->put($foreignPath, 'foreign');

        $signedUrl = URL::temporarySignedRoute(
            'api.tenants.files.serve',
            now()->addHour(),
            ['path' => $foreignPath]
        );

        $this->get($signedUrl)->assertForbidden();
    }

    #[Test]
    public function it_rejects_path_traversal_attempts(): void
    {
        $this->postJson('/api/tenant/files/signed-url', [
            'path' => 'families/'.$this->tenant->id.'/profiles/../'.$this->otherTenant->id.'/profiles/evil.jpg',
        ])->assertForbidden();
    }

    #[Test]
    public function it_rejects_unknown_storage_prefixes(): void
    {
        Storage::disk('public')->put('uploads/secret.txt', 'secret');

        $this->postJson('/api/tenant/files/signed-url', [
            'path' => 'uploads/secret.txt',
        ])->assertForbidden();
    }
}
