<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Notifications\Database\Seeders\NotificationDefinitionsSeeder;
use Modules\PastoralCare\Models\PastoralCareRequest;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\TenantDemoVerifier;
use Modules\Tenants\Database\Seeders\TenantDemoDataOrchestratorSeeder;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\ChurchSocialMedia;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantDemoDataOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NotificationDefinitionsSeeder::class);
    }

    #[Test]
    public function orchestrator_seeds_church_presence_idempotently_when_families_skipped(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Demo Parish Orchestrator',
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
        ]);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'active' => 1,
        ]);

        putenv(TenantDemoMarkers::ENV_TENANT_ID.'='.$tenant->id);
        putenv(TenantDemoMarkers::ENV_SKIP_FAMILIES.'=1');
        putenv(TenantDemoMarkers::ENV_SKIP_STEWARDSHIP.'=1');
        putenv(TenantDemoMarkers::ENV_SKIP_BCC_LEADERSHIP.'=1');

        $this->seed(TenantDemoDataOrchestratorSeeder::class);
        $this->seed(TenantDemoDataOrchestratorSeeder::class);

        $this->assertTrue(ChurchProfile::query()->where('tenant_id', $tenant->id)->exists());
        $this->assertGreaterThanOrEqual(1, ChurchSocialMedia::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(
            4,
            PastoralCareRequest::query()->where('tenant_id', $tenant->id)->where('notes', TenantDemoMarkers::MARKER)->count()
        );

        $errors = TenantDemoVerifier::verify((int) $tenant->id);
        $this->assertNotContains('Church profile missing.', $errors);
        $this->assertNotContains('Expected pastoral care demo requests.', $errors);
    }
}
