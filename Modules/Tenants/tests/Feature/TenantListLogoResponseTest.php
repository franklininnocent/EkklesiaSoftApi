<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Tests\Support\MediaSecurityFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantListLogoResponseTest extends TestCase
{
    #[Test]
    public function tenant_list_includes_signed_logo_full_url_for_super_admin(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $tenant = Tenant::factory()->active()->create();
        $role = Role::query()->firstOrCreate(
            ['name' => Role::SUPER_ADMIN, 'tenant_id' => null],
            [
                'description' => 'Super Admin',
                'level' => Role::LEVEL_SUPER_ADMIN,
                'active' => 1,
            ]
        );
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->roles()->syncWithoutDetaching([$role->id]);
        Passport::actingAs($admin);

        $this->postJson("/api/tenant/{$tenant->id}/logo", [
            'logo' => MediaSecurityFixtures::validJpeg(200, 200),
        ])->assertOk();

        $response = $this->getJson('/api/tenant/list?per_page=all');
        $response->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', $tenant->id);
        $this->assertNotNull($item);
        $this->assertNotEmpty($item['logo_full_url'] ?? null);
        $this->assertStringContainsString('/api/tenant/media/serve', $item['logo_full_url']);

        $this->actingAsGuest();
        $this->get($item['logo_full_url'])->assertOk();
    }
}
