<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Subscriptions\Models\SubscriptionUpgradeRequest;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class UpgradeRequestApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function church_admin_can_request_a_plan_and_the_tenant_comes_from_the_session(): void
    {
        $other = Tenant::factory()->create();
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');

        $response = $this->postJson('/api/tenant/subscription/upgrade-requests', [
            'plan_id' => $this->plan('STANDARD')->id,
            'billing_interval' => 'annual',
            'feature_code' => 'contribution_plans',
            'message' => '<b>We need</b> dues plans',
            'tenant_id' => $other->id,
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.requested_plan.code', 'STANDARD')
            ->assertJsonPath('data.current_plan.code', 'STARTER')
            ->assertJsonPath('data.billing_interval', 'ANNUAL')
            ->assertJsonPath('data.feature_code', 'CONTRIBUTION_PLANS')
            ->assertJsonPath('data.message', 'We need dues plans');

        $this->assertDatabaseHas('subscription_upgrade_requests', ['tenant_id' => $ctx['tenant']->id, 'status' => 'PENDING']);
        $this->assertDatabaseMissing('subscription_upgrade_requests', ['tenant_id' => $other->id]);
        $this->assertSame('STARTER', $this->currentSubscription($ctx['tenant'])->plan->code, 'Requesting never changes the plan.');
        $this->assertDatabaseHas('tenant_subscription_audits', ['tenant_id' => $ctx['tenant']->id, 'operation' => 'upgrade_request_submitted']);
    }

    #[Test]
    public function only_one_open_request_and_no_request_for_the_current_or_hidden_plan(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');

        $this->postJson('/api/tenant/subscription/upgrade-requests', ['plan_id' => $this->plan('STARTER')->id])
            ->assertStatus(422)->assertJsonPath('code', 'PLAN_CHANGE_NOT_ALLOWED');
        $this->postJson('/api/tenant/subscription/upgrade-requests', ['plan_id' => $this->plan('LEGACY_FREE')->id])
            ->assertStatus(422)->assertJsonPath('code', 'PLAN_NOT_AVAILABLE');

        $this->postJson('/api/tenant/subscription/upgrade-requests', ['plan_id' => $this->plan('STANDARD')->id])->assertCreated();
        $this->postJson('/api/tenant/subscription/upgrade-requests', ['plan_id' => $this->plan('PROFESSIONAL')->id])
            ->assertStatus(422)->assertJsonPath('code', 'PLAN_CHANGE_NOT_ALLOWED');
    }

    #[Test]
    public function a_plan_can_be_requested_by_its_public_code(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');

        $this->postJson('/api/tenant/subscription/upgrade-requests', ['plan_code' => 'legacy_free'])
            ->assertStatus(422)->assertJsonPath('code', 'PLAN_NOT_AVAILABLE');
        $this->postJson('/api/tenant/subscription/upgrade-requests', [])->assertStatus(422);
        $this->postJson('/api/tenant/subscription/upgrade-requests', ['plan_code' => 'professional'])
            ->assertCreated()->assertJsonPath('data.requested_plan.code', 'PROFESSIONAL');
    }

    #[Test]
    public function staff_without_subscription_access_cannot_request_or_list(): void
    {
        $this->asStaff();

        $this->getJson('/api/tenant/subscription/upgrade-requests')->assertForbidden();
        $this->postJson('/api/tenant/subscription/upgrade-requests', ['plan_id' => $this->plan('STANDARD')->id])->assertForbidden();
        $this->assertDatabaseCount('subscription_upgrade_requests', 0);
    }

    #[Test]
    public function church_lists_only_its_own_requests(): void
    {
        $otherAdmin = $this->asTenantAdmin();
        $this->assignPlan($otherAdmin['tenant'], 'STARTER');
        $this->postJson('/api/tenant/subscription/upgrade-requests', ['plan_id' => $this->plan('STANDARD')->id])->assertCreated();

        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');

        $this->getJson('/api/tenant/subscription/upgrade-requests')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function tenant_users_cannot_use_the_review_queue(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $id = $this->postJson('/api/tenant/subscription/upgrade-requests', ['plan_id' => $this->plan('STANDARD')->id])->json('data.id');

        $this->getJson('/api/admin/subscriptions/upgrade-requests')->assertForbidden();
        $this->postJson("/api/admin/subscriptions/upgrade-requests/{$id}/approve")->assertForbidden();
        $this->assertSame('STARTER', $this->currentSubscription($ctx['tenant'])->plan->code);
    }

    #[Test]
    public function ekklesia_admin_approves_and_the_plan_changes_through_the_single_change_path(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $id = $this->postJson('/api/tenant/subscription/upgrade-requests', [
            'plan_id' => $this->plan('STANDARD')->id,
            'billing_interval' => 'ANNUAL',
        ])->json('data.id');

        $this->asEkklesiaAdmin();
        $this->getJson('/api/admin/subscriptions/upgrade-requests')->assertOk()
            ->assertJsonPath('meta.open_count', 1)
            ->assertJsonPath('data.0.tenant.id', $ctx['tenant']->id);

        $this->postJson("/api/admin/subscriptions/upgrade-requests/{$id}/approve", ['note' => 'Welcome to Standard'])
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $current = $this->currentSubscription($ctx['tenant']);
        $this->assertSame('STANDARD', $current->plan->code);
        $this->assertSame('ANNUAL', $current->billing_interval);
        $this->assertSame(TenantSubscription::SOURCE_UPGRADE_REQUEST, $current->source);
        $this->assertSame($current->id, SubscriptionUpgradeRequest::query()->find($id)->resulting_subscription_id);

        $this->postJson("/api/admin/subscriptions/upgrade-requests/{$id}/reject", ['note' => 'Too late'])
            ->assertStatus(409);
    }

    #[Test]
    public function reject_and_request_info_need_a_note_and_leave_the_plan_alone(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $id = $this->postJson('/api/tenant/subscription/upgrade-requests', ['plan_id' => $this->plan('PROFESSIONAL')->id])->json('data.id');
        $admin = $ctx['user'];

        $this->asEkklesiaAdmin();
        $this->postJson("/api/admin/subscriptions/upgrade-requests/{$id}/request-info", [])->assertStatus(422);
        $this->postJson("/api/admin/subscriptions/upgrade-requests/{$id}/request-info", ['note' => 'How many families?'])
            ->assertOk()->assertJsonPath('data.status', 'INFO_REQUESTED');

        Passport::actingAs($admin);
        $this->postJson('/api/tenant/subscription/upgrade-requests', ['plan_id' => $this->plan('PROFESSIONAL')->id, 'message' => 'About 1,800'])
            ->assertCreated()->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'PENDING');

        $this->asEkklesiaAdmin();
        $this->postJson("/api/admin/subscriptions/upgrade-requests/{$id}/reject", ['note' => 'Please choose Standard first'])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $this->assertSame('STARTER', $this->currentSubscription($ctx['tenant'])->plan->code);
    }

    #[Test]
    public function an_expired_read_only_church_can_still_send_a_request(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $ctx['tenant']->forceFill([
            'subscription_ends_at' => now()->subDays(60),
            'trial_ends_at' => null,
            'subscription_suspended_at' => null,
        ])->save();

        $this->postJson('/api/tenant/subscription/upgrade-requests', ['plan_id' => $this->plan('STANDARD')->id])->assertCreated();
    }
}
