<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantApiGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tenants.api.pagination.enabled' => true,
            'tenants.api.pagination.max_per_page' => 100,
            'tenants.api.rate_limit.enabled' => true,
            'tenants.api.rate_limit.buckets.default' => [
                'max_attempts' => 3,
                'decay_seconds' => 60,
            ],
        ]);

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        Family::factory()->create(['tenant_id' => $this->tenant->id]);

        $permission = Permission::query()->firstOrCreate(
            ['name' => 'families.view'],
            [
                'display_name' => 'View Families',
                'module' => 'Families',
                'category' => 'families',
                'scope' => Permission::SCOPE_TENANT,
                'active' => 1,
                'tenant_id' => null,
                'is_custom' => false,
            ]
        );
        $this->user->permissions()->syncWithoutDetaching([$permission->id]);

        Passport::actingAs($this->user);
        RateLimiter::clear($this->rateLimitKey());
    }

    #[Test]
    public function it_clamps_large_per_page_values_on_list_endpoints(): void
    {
        $response = $this->getJson('/api/families?per_page=500');

        $response->assertOk()
            ->assertJsonPath('per_page', 100);
    }

    #[Test]
    public function it_returns_429_when_default_rate_limit_is_exceeded(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/families?per_page=10')->assertOk();
        }

        $this->getJson('/api/families?per_page=10')
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertHeader('X-RateLimit-Limit', '3');
    }

    private function rateLimitKey(): string
    {
        return 'api:default:t'.$this->tenant->id.':u'.$this->user->id.':127.0.0.1';
    }
}
