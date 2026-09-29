<?php

namespace Modules\SupportAccess\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class SupportPlatformOperatorIsolationTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    #[Test]
    public function tenant_administrator_cannot_list_support_tenants(): void
    {
        $ctx = $this->asTenantAdmin();
        Passport::actingAs($ctx['user']);

        $this->getJson('/api/support/tenants')
            ->assertForbidden();
    }

    #[Test]
    public function tenant_administrator_cannot_access_platform_tenant_list(): void
    {
        $ctx = $this->asTenantAdmin();
        Passport::actingAs($ctx['user']);

        $this->getJson('/api/tenant/list')
            ->assertForbidden();
    }
}
