<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class TenantPlanComparisonApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function enterprise_lifetime_subscription_is_flagged_as_current_with_quote_pricing(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignLifetime($ctx['tenant'], 'ENTERPRISE');

        $response = $this->getJson('/api/tenant/subscription/comparison')->assertOk();
        $enterprise = collect($response->json('data.plans'))->firstWhere('code', 'ENTERPRISE');

        $this->assertTrue($enterprise['is_current']);
        $this->assertSame('current', $enterprise['primary_action']);
        $this->assertSame('CUSTOM', $enterprise['pricing_type']);
        $this->assertTrue($enterprise['is_lifetime']);
        $this->assertNull($enterprise['subscription_ends_at']);
        $this->assertSame('LIFETIME', $enterprise['subscription_status']);
        $this->assertSame('ENTERPRISE', $response->json('data.current_plan.code'));
        $this->assertTrue($response->json('data.current_plan.matched'));

        $overview = $this->getJson('/api/tenant/subscription/overview')->assertOk();
        $this->assertSame('ENTERPRISE', $overview->json('data.plan.code'));
        $this->assertTrue(collect($overview->json('data.comparison.plans'))->firstWhere('code', 'ENTERPRISE')['is_current']);

        $mine = $this->getJson('/api/tenant/my-subscription')->assertOk();
        $this->assertSame('ENTERPRISE', $mine->json('data.plan.code'));
        $this->assertSame('LIFETIME', $mine->json('data.status'));
    }

    #[Test]
    public function lifetime_enterprise_without_a_subscription_row_still_matches_by_plan_key(): void
    {
        $ctx = $this->asTenantAdmin();
        $ctx['tenant']->forceFill([
            'plan' => 'enterprise',
            'subscription_ends_at' => null,
            'trial_ends_at' => null,
        ])->save();

        $this->assertNull($this->currentSubscription($ctx['tenant']));

        $enterprise = collect($this->getJson('/api/tenant/subscription/comparison')->json('data.plans'))
            ->firstWhere('code', 'ENTERPRISE');

        $this->assertTrue($enterprise['is_current']);
        $this->assertSame('tenant_plan_key', $this->getJson('/api/tenant/subscription/comparison')->json('data.current_plan.source'));
        $this->assertSame('current', $enterprise['primary_action']);

        $this->postJson('/api/tenant/subscription/upgrade-requests', ['plan_code' => 'ENTERPRISE'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PLAN_CHANGE_NOT_ALLOWED');
    }

    #[Test]
    public function matching_uses_plan_code_not_the_display_name(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignLifetime($ctx['tenant'], 'ENTERPRISE');
        $this->plan('ENTERPRISE')->update(['name' => 'Enterprise Gold']);
        $this->plan('STANDARD')->update(['name' => 'enterprise']);
        Cache::flush();

        $plans = collect($this->getJson('/api/tenant/subscription/comparison')->json('data.plans'));
        $this->assertTrue($plans->firstWhere('code', 'ENTERPRISE')['is_current']);
        $this->assertFalse($plans->firstWhere('code', 'STANDARD')['is_current']);
        $this->assertSame('Enterprise Gold', $plans->firstWhere('code', 'ENTERPRISE')['name']);
    }

    #[Test]
    public function a_renamed_and_versioned_current_plan_stays_matched_by_id(): void
    {
        $ctx = $this->asTenantAdmin();
        $subscription = $this->assignLifetime($ctx['tenant'], 'STARTER');
        $pinnedVersion = (int) $subscription->plan_version_id;

        $this->plan('STARTER')->update(['name' => 'Parish Starter']);
        $this->asSuperAdmin();
        $planId = $this->plan('STARTER')->id;
        $draft = $this->postJson("/api/admin/subscriptions/plans/{$planId}/versions", [])->assertCreated();
        $this->postJson("/api/admin/subscriptions/plans/{$planId}/versions/{$draft->json('data.id')}/publish", ['reason' => 'Publish version 2'])
            ->assertOk();

        $this->asTenantAdmin($ctx['tenant']);
        $card = collect($this->getJson('/api/tenant/subscription/comparison')->json('data.plans'))->firstWhere('code', 'STARTER');
        $this->assertTrue($card['is_current']);
        $this->assertTrue($card['using_subscribed_version']);
        $this->assertSame($pinnedVersion, $this->currentSubscription($ctx['tenant'])->plan_version_id);
        $this->assertSame('Parish Starter', $card['name']);
    }

    #[Test]
    public function archived_current_plan_is_appended_and_no_other_plan_is_selected(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignLifetime($ctx['tenant'], 'ENTERPRISE');
        $this->plan('ENTERPRISE')->update([
            'status' => Plan::STATUS_ARCHIVED,
            'is_public' => false,
            'archived_at' => now(),
        ]);
        Cache::flush();

        $data = $this->getJson('/api/tenant/subscription/comparison')->json('data');
        $current = collect($data['plans'])->firstWhere('is_current', true);

        $this->assertNotNull($current);
        $this->assertSame('ENTERPRISE', $current['code']);
        $this->assertFalse($current['listed_in_catalog']);
        $this->assertSame('current', $current['primary_action']);
        $this->assertCount(1, collect($data['plans'])->where('is_current', true));
        $this->assertFalse($data['current_plan_listed']);
    }

    #[Test]
    public function unmatched_plan_does_not_silently_select_another_card(): void
    {
        $ctx = $this->asTenantAdmin();
        $ctx['tenant']->forceFill(['plan' => 'unknown-plan-key', 'subscription_ends_at' => null])->save();

        $data = $this->getJson('/api/tenant/subscription/comparison')->json('data');
        $this->assertFalse($data['current_plan']['matched']);
        $this->assertSame('unmatched', $data['current_plan']['source']);
        $this->assertEmpty(collect($data['plans'])->where('is_current', true));
    }

    #[Test]
    public function other_lifecycle_statuses_still_identify_the_current_plan(): void
    {
        foreach ([
            ['trial_ends_at' => now()->addDays(10), 'subscription_ends_at' => now()->addYear(), 'expected' => 'TRIAL'],
            ['trial_ends_at' => null, 'subscription_ends_at' => now()->addDays(3), 'expected' => 'EXPIRING'],
            ['trial_ends_at' => null, 'subscription_ends_at' => now()->subDay(), 'expected' => 'GRACE_PERIOD'],
            ['trial_ends_at' => null, 'subscription_ends_at' => now()->subYear(), 'expected' => 'EXPIRED'],
        ] as $case) {
            $ctx = $this->asTenantAdmin();
            $this->assignPlan($ctx['tenant'], 'STANDARD');
            $ctx['tenant']->forceFill([
                'trial_ends_at' => $case['trial_ends_at'],
                'subscription_ends_at' => $case['subscription_ends_at'],
                'subscription_suspended_at' => null,
            ])->save();

            $card = collect($this->getJson('/api/tenant/subscription/comparison')->json('data.plans'))
                ->firstWhere('code', 'STANDARD');
            $this->assertTrue($card['is_current'], $case['expected']);
            $this->assertSame($case['expected'], $card['subscription_status']);
            $this->assertSame('current', $card['primary_action']);
        }
    }

    #[Test]
    public function comparison_is_scoped_to_the_session_tenant_and_rejects_spoofed_ids(): void
    {
        $other = Tenant::factory()->create(['plan' => 'starter']);
        $this->assignPlan($other, 'STARTER');

        $ctx = $this->asTenantAdmin();
        $this->assignLifetime($ctx['tenant'], 'ENTERPRISE');

        $data = $this->getJson('/api/tenant/subscription/comparison?tenant_id='.$other->id)->json('data');
        $this->assertSame('ENTERPRISE', $data['current_plan']['code']);
        $this->assertNotSame('STARTER', $data['current_plan']['code']);
        $this->assertSame($this->plan('ENTERPRISE')->id, $data['current_plan']['id']);
    }

    #[Test]
    public function unauthorized_and_unauthenticated_actors_cannot_read_comparison(): void
    {
        $this->getJson('/api/tenant/subscription/comparison')->assertUnauthorized();

        $this->asStaff();
        $this->getJson('/api/tenant/subscription/comparison')->assertForbidden();
        $this->getJson('/api/tenant/subscription/overview')->assertForbidden();
    }

    #[Test]
    public function public_catalog_does_not_include_tenant_current_plan_flags(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignLifetime($ctx['tenant'], 'ENTERPRISE');

        $public = $this->getJson('/api/public/subscription-plans')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('is_current', $public);
        $this->assertArrayNotHasKey('primary_action', $public);
        $this->assertArrayNotHasKey('subscription_status', $public);
    }

    private function assignLifetime(Tenant $tenant, string $code): TenantSubscription
    {
        $subscription = $this->assignPlan($tenant, $code);
        $tenant->forceFill([
            'subscription_ends_at' => null,
            'trial_ends_at' => null,
            'subscription_suspended_at' => null,
        ])->save();

        return $subscription;
    }
}
