<?php

namespace Modules\ApplicationAccess\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Modules\ApplicationAccess\Http\Middleware\AssignRequestId;
use Modules\ApplicationAccess\Support\ApplicationAccessPermissionCatalog;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssignRequestIdMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->instance(TenantContext::class, TenantContext::empty());
        User::flushRequestPermissionCache();

        Role::query()->updateOrCreate(
            ['name' => Role::SUPER_ADMIN],
            [
                'description' => Role::SUPER_ADMIN,
                'level' => Role::LEVEL_SUPER_ADMIN,
                'active' => 1,
                'tenant_id' => null,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );
        ApplicationAccessPermissionCatalog::syncPermissionsAndRoles();
    }

    #[Test]
    public function it_echoes_generated_request_id_header(): void
    {
        $role = Role::query()->where('name', Role::SUPER_ADMIN)->firstOrFail();
        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
            'password' => Hash::make('password'),
        ]);

        Passport::actingAs($user);

        $response = $this->getJson('/api/admin/application-access/health');

        $response->assertOk();
        $this->assertNotEmpty($response->headers->get(AssignRequestId::HEADER));
    }

    #[Test]
    public function it_honors_incoming_request_id_header(): void
    {
        $role = Role::query()->where('name', Role::SUPER_ADMIN)->firstOrFail();
        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
            'password' => Hash::make('password'),
        ]);

        Passport::actingAs($user);

        $response = $this->withHeader(AssignRequestId::HEADER, 'correlation-abc-123')
            ->getJson('/api/admin/application-access/health');

        $response->assertOk();
        $this->assertSame('correlation-abc-123', $response->headers->get(AssignRequestId::HEADER));
    }
}
