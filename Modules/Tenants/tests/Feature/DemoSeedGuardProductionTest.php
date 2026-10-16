<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\Tenants\Database\Seeders\Support\DemoSeedGuard;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\TenantMinistriesDemoSeeder;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DemoSeedGuardProductionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function production_blocks_direct_child_demo_seeder_without_allow_flag(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Guard Parish',
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
        ]);
        User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);

        putenv(TenantDemoMarkers::ENV_TENANT_ID.'='.$tenant->id);
        $_ENV[TenantDemoMarkers::ENV_TENANT_ID] = (string) $tenant->id;
        putenv(DemoSeedGuard::ENV_ALLOW);
        unset($_ENV[DemoSeedGuard::ENV_ALLOW], $_SERVER[DemoSeedGuard::ENV_ALLOW]);
        putenv(DemoSeedGuard::ENV_CLI_FORCE);
        unset($_ENV[DemoSeedGuard::ENV_CLI_FORCE], $_SERVER[DemoSeedGuard::ENV_CLI_FORCE]);

        $this->app->detectEnvironment(fn () => 'production');

        $this->assertNotSame([], DemoSeedGuard::environmentBlocks());

        $orgCount = Organization::query()->where('tenant_id', $tenant->id)->count();
        (new TenantMinistriesDemoSeeder)->run();

        $this->assertSame($orgCount, Organization::query()->where('tenant_id', $tenant->id)->count());
    }
}
