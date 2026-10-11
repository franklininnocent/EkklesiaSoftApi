<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class TenantPlanPreviewChangeApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function tenant_admin_can_preview_upgrade_to_standard(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');

        $this->postJson('/api/tenant/subscription/preview-plan-change', ['plan_code' => 'STANDARD'])
            ->assertOk()
            ->assertJsonPath('data.requires_staff_approval', true)
            ->assertJsonPath('data.plan_changes_immediately', false)
            ->assertJsonPath('data.data_preserved', true);

        $gained = collect($this->postJson('/api/tenant/subscription/preview-plan-change', ['plan_code' => 'STANDARD'])->json('data.features_gained'))
            ->pluck('code');
        $this->assertTrue($gained->contains('FINANCIAL_DASHBOARD'));
    }
}
