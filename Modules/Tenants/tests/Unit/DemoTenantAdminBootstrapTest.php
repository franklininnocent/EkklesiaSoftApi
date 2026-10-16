<?php

namespace Modules\Tenants\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\DemoTenantAdminBootstrap;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DemoTenantAdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ensure_primary_admin_applies_configured_demo_credentials(): void
    {
        config()->set('tenants.demo.default_admin_email', 'shadmin@gmail.com');
        config()->set('tenants.demo.default_admin_password', 'TempPassword123!');
        config()->set('tenants.demo.default_admin_name', 'Sacred Heart Admin');

        $tenant = Tenant::factory()->active()->create();

        $admin = app(DemoTenantAdminBootstrap::class)->ensurePrimaryAdmin($tenant);

        $this->assertSame('shadmin@gmail.com', $admin->email);
        $this->assertTrue($admin->is_primary_admin);
        $this->assertTrue(Hash::check('TempPassword123!', $admin->password));

        $this->assertTrue(Role::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', Role::TENANT_ADMINISTRATOR)
            ->exists());
    }
}
