<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Models\SubscriptionCatalogAudit;
use Modules\Subscriptions\Models\SubscriptionUpgradeRequest;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class PlanCatalogAdminApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/admin/subscriptions/plans')->assertUnauthorized();
        $this->postJson('/api/admin/subscriptions/plans', [])->assertUnauthorized();
        $this->deleteJson('/api/admin/subscriptions/plans/1')->assertUnauthorized();
    }

    #[Test]
    public function tenant_admin_cannot_access_any_platform_subscription_api(): void
    {
        $ctx = $this->asTenantAdmin();
        $planId = $this->plan('STARTER')->id;

        $this->getJson('/api/admin/subscriptions/plans')->assertForbidden();
        $this->getJson('/api/admin/subscriptions/features')->assertForbidden();
        $this->postJson('/api/admin/subscriptions/plans', ['code' => 'HACK', 'name' => 'Hack', 'pricing_type' => 'FREE'])->assertForbidden();
        $this->patchJson("/api/admin/subscriptions/plans/{$planId}", ['name' => 'Hacked'])->assertForbidden();
        $this->postJson("/api/admin/subscriptions/tenants/{$ctx['tenant']->id}/assign", [
            'plan_id' => $this->plan('ENTERPRISE')->id,
            'reason' => 'self upgrade',
        ])->assertForbidden();
        $this->postJson("/api/admin/subscriptions/tenants/{$ctx['tenant']->id}/overrides", [
            'feature_code' => 'API_ACCESS',
            'mode' => 'ENABLE',
            'reason' => 'self grant',
        ])->assertForbidden();
        $this->putJson('/api/admin/subscriptions/policies', ['currency_code' => 'USD'])->assertForbidden();

        $this->assertSame('Starter', $this->plan('STARTER')->name);
    }

    #[Test]
    public function tenant_staff_cannot_access_platform_subscription_api(): void
    {
        $this->asStaff();

        $this->getJson('/api/admin/subscriptions/plans')->assertForbidden();
        $this->getJson('/api/admin/subscriptions/audits')->assertForbidden();
    }

    #[Test]
    public function super_admin_can_create_plan_edit_draft_and_publish(): void
    {
        $this->asSuperAdmin();

        $create = $this->postJson('/api/admin/subscriptions/plans', [
            'code' => 'parish_plus',
            'name' => 'Parish Plus',
            'pricing_type' => 'FIXED',
            'is_public' => true,
        ])->assertCreated();

        $planId = $create->json('data.id');
        $this->assertSame('PARISH_PLUS', $create->json('data.code'));
        $versionId = PlanVersion::query()->where('plan_id', $planId)->value('id');
        $this->assertNotNull($versionId);

        $this->patchJson("/api/admin/subscriptions/plans/{$planId}/versions/{$versionId}", [
            'monthly_price' => '1999.00',
            'annual_price' => '19990.00',
            'billing_intervals' => ['MONTHLY', 'ANNUAL'],
        ])->assertOk();

        $this->putJson("/api/admin/subscriptions/plans/{$planId}/versions/{$versionId}/entitlements", [
            'entitlements' => [
                ['feature_code' => 'contributions', 'is_enabled' => true],
                ['feature_code' => 'PEOPLE_LIMIT', 'is_enabled' => true, 'numeric_value' => 500],
            ],
        ])->assertOk();

        $this->postJson("/api/admin/subscriptions/plans/{$planId}/versions/{$versionId}/publish", ['reason' => 'Launch'])
            ->assertOk()
            ->assertJsonPath('data.status', PlanVersion::STATUS_ACTIVE);

        $this->assertTrue(SubscriptionCatalogAudit::query()->where('entity_type', 'plan_version')->where('entity_id', $versionId)->exists());

        $public = $this->getJson('/api/public/subscription-plans')->assertOk();
        $this->assertContains('PARISH_PLUS', array_column($public->json('data'), 'code'));
    }

    #[Test]
    public function published_versions_are_immutable(): void
    {
        $this->asSuperAdmin();
        $plan = $this->plan('STARTER');
        $version = $plan->activeVersion;

        $this->patchJson("/api/admin/subscriptions/plans/{$plan->id}/versions/{$version->id}", ['monthly_price' => '1.00'])
            ->assertStatus(409);
        $this->putJson("/api/admin/subscriptions/plans/{$plan->id}/versions/{$version->id}/entitlements", [
            'entitlements' => [['feature_code' => 'API_ACCESS', 'is_enabled' => true]],
        ])->assertStatus(409);
        $this->deleteJson("/api/admin/subscriptions/plans/{$plan->id}/versions/{$version->id}")->assertStatus(409);

        $this->assertSame('1499.00', (string) $version->fresh()->monthly_price);
    }

    #[Test]
    public function version_must_belong_to_the_plan_in_the_route(): void
    {
        $this->asSuperAdmin();
        $starter = $this->plan('STARTER');
        $standardVersion = $this->plan('STANDARD')->activeVersion;

        $this->getJson("/api/admin/subscriptions/plans/{$starter->id}/versions/{$standardVersion->id}")->assertNotFound();
    }

    #[Test]
    public function ekklesia_admin_can_view_but_not_change_the_catalog(): void
    {
        $this->asEkklesiaAdmin();
        $plan = $this->plan('STARTER');

        $this->getJson('/api/admin/subscriptions/plans')->assertOk();
        $this->getJson('/api/admin/subscriptions/matrix')->assertOk();
        $this->getJson('/api/admin/subscriptions/policies')->assertOk();

        $this->postJson('/api/admin/subscriptions/plans', ['code' => 'NEW_PLAN', 'name' => 'New', 'pricing_type' => 'FREE'])
            ->assertForbidden()
            ->assertJsonPath('missing_permissions', ['subscriptions.plans.manage']);
        $this->postJson("/api/admin/subscriptions/plans/{$plan->id}/archive", ['reason' => 'x'])->assertForbidden();
        $this->postJson('/api/admin/subscriptions/features', ['code' => 'NEW_FEATURE', 'name' => 'New', 'feature_type' => 'BOOLEAN'])->assertForbidden();
        $this->putJson('/api/admin/subscriptions/policies', ['currency_code' => 'USD'])->assertForbidden();
    }

    #[Test]
    public function default_plan_cannot_be_archived(): void
    {
        $this->asSuperAdmin();
        $starter = $this->plan('STARTER');

        $this->postJson("/api/admin/subscriptions/plans/{$starter->id}/archive", ['reason' => 'cleanup'])
            ->assertStatus(422);
        $this->assertFalse($starter->fresh()->isArchived());
    }

    #[Test]
    public function super_admin_feature_catalog_includes_mass_intentions(): void
    {
        $this->asSuperAdmin();

        $codes = collect($this->getJson('/api/admin/subscriptions/features')->assertOk()->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('MASS_INTENTIONS'));

        $mass = collect($this->getJson('/api/admin/subscriptions/features')->json('data'))->firstWhere('code', 'MASS_INTENTIONS');
        $this->assertSame('Mass Intentions', $mass['name']);
        $this->assertSame('mass_intentions', $mass['legacy_key']);
        $this->assertTrue($mass['is_active']);
        $this->assertFalse($mass['is_core']);
    }

    #[Test]
    public function feature_dependency_cycles_are_rejected(): void
    {
        $this->asSuperAdmin();

        $a = $this->postJson('/api/admin/subscriptions/features', ['code' => 'CYCLE_A', 'name' => 'Cycle A', 'feature_type' => 'BOOLEAN'])->assertCreated()->json('data.id');
        $b = $this->postJson('/api/admin/subscriptions/features', ['code' => 'CYCLE_B', 'name' => 'Cycle B', 'feature_type' => 'BOOLEAN'])->assertCreated()->json('data.id');

        $this->putJson("/api/admin/subscriptions/features/{$a}/dependencies", ['requires' => ['CYCLE_B']])->assertOk();
        $this->putJson("/api/admin/subscriptions/features/{$b}/dependencies", ['requires' => ['CYCLE_A']])->assertStatus(422);
    }

    #[Test]
    public function policies_reject_unknown_or_dangerous_keys(): void
    {
        $this->asSuperAdmin();

        $this->putJson('/api/admin/subscriptions/policies', ['over_limit_behavior' => 'DELETE_DATA'])->assertStatus(422);
        $this->putJson('/api/admin/subscriptions/policies', ['limit_exempt_flows' => ['everything']])->assertStatus(422);

        $this->putJson('/api/admin/subscriptions/policies', ['usage_thresholds' => [80, 100], 'reason' => 'Tune'])
            ->assertOk();
        $this->getJson('/api/admin/subscriptions/policies')->assertJsonPath('data.policies.downgrade_behavior', 'PRESERVE_DATA');
    }

    #[Test]
    public function unlinked_plan_can_be_updated_preserving_identity(): void
    {
        $this->asSuperAdmin();

        $create = $this->postJson('/api/admin/subscriptions/plans', [
            'code' => 'EDITABLE_PLAN',
            'name' => 'Editable Plan',
            'pricing_type' => 'FIXED',
            'short_description' => 'Before',
        ])->assertCreated();

        $planId = (int) $create->json('data.id');
        $code = $create->json('data.code');
        $this->assertTrue($create->json('data.is_editable'));
        $this->assertNull($create->json('data.edit_restriction'));

        $this->patchJson("/api/admin/subscriptions/plans/{$planId}", [
            'name' => 'Editable Plan Renamed',
            'short_description' => 'After',
            'is_public' => true,
            'badge_label' => 'New',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $planId)
            ->assertJsonPath('data.code', $code)
            ->assertJsonPath('data.name', 'Editable Plan Renamed')
            ->assertJsonPath('data.short_description', 'After')
            ->assertJsonPath('data.is_public', true)
            ->assertJsonPath('data.badge_label', 'New')
            ->assertJsonPath('data.is_editable', true);

        $this->assertTrue(
            SubscriptionCatalogAudit::query()
                ->where('entity_type', 'plan')
                ->where('entity_id', $planId)
                ->where('operation', 'plan_updated')
                ->exists()
        );
    }

    #[Test]
    public function assigned_plan_allows_safe_header_edits_and_new_versions(): void
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);
        $this->asSuperAdmin();

        $create = $this->postJson('/api/admin/subscriptions/plans', [
            'code' => 'LINKED_PLAN',
            'name' => 'Linked Plan',
            'pricing_type' => 'FREE',
            'is_assignable' => true,
        ])->assertCreated();
        $planId = (int) $create->json('data.id');
        $versionId = (int) PlanVersion::query()->where('plan_id', $planId)->value('id');

        $this->postJson("/api/admin/subscriptions/plans/{$planId}/versions/{$versionId}/publish", ['reason' => 'Launch'])
            ->assertOk();

        $this->assignPlan($tenant, 'LINKED_PLAN');
        $subscription = TenantSubscription::query()->forTenant((int) $tenant->id)->current()->firstOrFail();
        $pinnedVersion = (int) $subscription->plan_version_id;
        $contracted = $subscription->contracted_price;

        $this->getJson("/api/admin/subscriptions/plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.is_editable', true)
            ->assertJsonPath('data.edit_restriction', null)
            ->assertJsonPath('data.edit_policy.tenant_count', 1);

        $this->patchJson("/api/admin/subscriptions/plans/{$planId}", ['name' => 'Linked Plan Renamed', 'badge_label' => 'Popular'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Linked Plan Renamed')
            ->assertJsonPath('data.badge_label', 'Popular');

        $subscription->refresh();
        $this->assertSame($pinnedVersion, (int) $subscription->plan_version_id);
        $this->assertSame($contracted, $subscription->contracted_price);

        $this->postJson("/api/admin/subscriptions/plans/{$planId}/versions", [])
            ->assertCreated();
    }

    #[Test]
    public function assigned_plan_restricted_fields_require_confirmation(): void
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);
        $this->asSuperAdmin();

        $create = $this->postJson('/api/admin/subscriptions/plans', [
            'code' => 'RESTRICTED_PLAN',
            'name' => 'Restricted Plan',
            'pricing_type' => 'FREE',
            'is_assignable' => true,
            'is_public' => true,
        ])->assertCreated();
        $planId = (int) $create->json('data.id');
        $versionId = (int) PlanVersion::query()->where('plan_id', $planId)->value('id');
        $this->postJson("/api/admin/subscriptions/plans/{$planId}/versions/{$versionId}/publish", ['reason' => 'Launch'])->assertOk();
        $this->assignPlan($tenant, 'RESTRICTED_PLAN');

        $this->patchJson("/api/admin/subscriptions/plans/{$planId}", ['is_assignable' => false])
            ->assertStatus(409)
            ->assertJsonPath('code', SubscriptionException::PLAN_EDIT_REQUIRES_CONFIRMATION);

        $this->patchJson("/api/admin/subscriptions/plans/{$planId}", [
            'is_assignable' => false,
            'confirm_assignment_impact' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_assignable', false);
    }

    #[Test]
    public function archive_occupied_plan_requires_confirmation(): void
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);
        $this->asSuperAdmin();

        $create = $this->postJson('/api/admin/subscriptions/plans', [
            'code' => 'ARCHIVE_OCCUPIED',
            'name' => 'Archive Occupied',
            'pricing_type' => 'FREE',
        ])->assertCreated();
        $planId = (int) $create->json('data.id');
        $versionId = (int) PlanVersion::query()->where('plan_id', $planId)->value('id');
        $this->postJson("/api/admin/subscriptions/plans/{$planId}/versions/{$versionId}/publish", ['reason' => 'Launch'])->assertOk();
        $this->assignPlan($tenant, 'ARCHIVE_OCCUPIED');

        $this->postJson("/api/admin/subscriptions/plans/{$planId}/archive", ['reason' => 'Retire offering'])
            ->assertStatus(409)
            ->assertJsonPath('code', SubscriptionException::PLAN_ARCHIVE_REQUIRES_CONFIRMATION);

        $this->postJson("/api/admin/subscriptions/plans/{$planId}/archive", [
            'reason' => 'Retire offering',
            'confirm_assigned_churches' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Plan::STATUS_ARCHIVED);

        $this->assertTrue(
            TenantSubscription::query()->forTenant((int) $tenant->id)->current()->where('plan_id', $planId)->exists()
        );
    }

    #[Test]
    public function pending_assignment_allows_safe_rename(): void
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);
        $this->assignPlan($tenant, 'STARTER');
        $this->asSuperAdmin();

        $create = $this->postJson('/api/admin/subscriptions/plans', [
            'code' => 'PENDING_TARGET',
            'name' => 'Pending Target',
            'pricing_type' => 'FREE',
        ])->assertCreated();
        $planId = (int) $create->json('data.id');
        $versionId = (int) PlanVersion::query()->where('plan_id', $planId)->value('id');
        $this->postJson("/api/admin/subscriptions/plans/{$planId}/versions/{$versionId}/publish", ['reason' => 'Launch'])->assertOk();

        $this->postJson("/api/admin/subscriptions/tenants/{$tenant->id}/assign", [
            'plan_id' => $planId,
            'reason' => 'Schedule move',
            'confirm_impact' => true,
            'scheduled_for' => now()->addDays(7)->toIso8601String(),
        ])->assertOk();

        $this->patchJson("/api/admin/subscriptions/plans/{$planId}", ['name' => 'Pending Target Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Pending Target Renamed');
    }

    #[Test]
    public function plan_update_rejects_short_name_like_create(): void
    {
        $this->asSuperAdmin();
        $create = $this->postJson('/api/admin/subscriptions/plans', [
            'code' => 'NAME_CHECK',
            'name' => 'Name Check',
            'pricing_type' => 'FREE',
        ])->assertCreated();
        $planId = (int) $create->json('data.id');

        $this->patchJson("/api/admin/subscriptions/plans/{$planId}", ['name' => 'A'])->assertStatus(422);
    }

    #[Test]
    public function superseded_subscriptions_do_not_block_plan_edits(): void
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);
        $this->asSuperAdmin();

        $create = $this->postJson('/api/admin/subscriptions/plans', [
            'code' => 'ONCE_USED',
            'name' => 'Once Used',
            'pricing_type' => 'FREE',
        ])->assertCreated();
        $planId = (int) $create->json('data.id');
        $versionId = (int) PlanVersion::query()->where('plan_id', $planId)->value('id');
        $this->postJson("/api/admin/subscriptions/plans/{$planId}/versions/{$versionId}/publish", ['reason' => 'Launch'])->assertOk();

        $this->assignPlan($tenant, 'ONCE_USED');
        $this->assignPlan($tenant, 'STARTER');

        $this->patchJson("/api/admin/subscriptions/plans/{$planId}", ['name' => 'Once Used Again'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Once Used Again')
            ->assertJsonPath('data.is_editable', true);
    }

    #[Test]
    public function super_admin_can_delete_unused_catalog_plan(): void
    {
        $this->asSuperAdmin();

        $create = $this->postJson('/api/admin/subscriptions/plans', [
            'code' => 'DELETABLE_PLAN',
            'name' => 'Deletable Plan',
            'pricing_type' => 'FREE',
        ])->assertCreated();

        $planId = (int) $create->json('data.id');
        $this->assertTrue($create->json('data.can_delete'));

        $this->deleteJson("/api/admin/subscriptions/plans/{$planId}", ['reason' => 'Test cleanup'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Plan::withTrashed()->findOrFail($planId)->trashed());
        $this->getJson("/api/admin/subscriptions/plans/{$planId}")->assertNotFound();
        $this->getJson('/api/admin/subscriptions/plans')->assertOk()
            ->assertJsonMissing(['code' => 'DELETABLE_PLAN']);

        $this->assertTrue(
            SubscriptionCatalogAudit::query()
                ->where('entity_type', 'plan')
                ->where('entity_id', $planId)
                ->where('operation', 'plan_deleted')
                ->exists()
        );
    }

    #[Test]
    public function plan_with_assigned_church_cannot_be_deleted(): void
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);
        $this->asSuperAdmin();

        $create = $this->postJson('/api/admin/subscriptions/plans', [
            'code' => 'LINKED_DELETE',
            'name' => 'Linked Delete',
            'pricing_type' => 'FREE',
        ])->assertCreated();
        $planId = (int) $create->json('data.id');
        $versionId = (int) PlanVersion::query()->where('plan_id', $planId)->value('id');
        $this->postJson("/api/admin/subscriptions/plans/{$planId}/versions/{$versionId}/publish", ['reason' => 'Launch'])->assertOk();

        $this->assignPlan($tenant, 'LINKED_DELETE');

        $message = 'This plan cannot be deleted because it is currently associated with one or more churches. Remove the associated churches before deleting this plan.';

        $this->getJson("/api/admin/subscriptions/plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.can_delete', false)
            ->assertJsonPath('data.delete_restriction.code', SubscriptionException::PLAN_HAS_ASSIGNED_CHURCHES);

        $this->deleteJson("/api/admin/subscriptions/plans/{$planId}")
            ->assertStatus(409)
            ->assertJsonPath('code', SubscriptionException::PLAN_HAS_ASSIGNED_CHURCHES)
            ->assertJsonPath('message', $message);

        $this->assertFalse(Plan::query()->findOrFail($planId)->trashed());
    }

    #[Test]
    public function plan_with_multiple_churches_cannot_be_deleted(): void
    {
        $tenants = Tenant::factory()->active()->count(2)->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);
        $this->asSuperAdmin();

        $create = $this->postJson('/api/admin/subscriptions/plans', [
            'code' => 'MULTI_LINKED',
            'name' => 'Multi Linked',
            'pricing_type' => 'FREE',
        ])->assertCreated();
        $planId = (int) $create->json('data.id');
        $versionId = (int) PlanVersion::query()->where('plan_id', $planId)->value('id');
        $this->postJson("/api/admin/subscriptions/plans/{$planId}/versions/{$versionId}/publish", ['reason' => 'Launch'])->assertOk();

        foreach ($tenants as $tenant) {
            $this->assignPlan($tenant, 'MULTI_LINKED');
        }

        $this->deleteJson("/api/admin/subscriptions/plans/{$planId}")
            ->assertStatus(409)
            ->assertJsonPath('code', SubscriptionException::PLAN_HAS_ASSIGNED_CHURCHES);
    }

    #[Test]
    public function plan_becomes_deletable_after_churches_are_reassigned(): void
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);
        $this->asSuperAdmin();

        $create = $this->postJson('/api/admin/subscriptions/plans', [
            'code' => 'REASSIGN_THEN_DEL',
            'name' => 'Reassign Then Delete',
            'pricing_type' => 'FREE',
        ])->assertCreated();
        $planId = (int) $create->json('data.id');
        $versionId = (int) PlanVersion::query()->where('plan_id', $planId)->value('id');
        $this->postJson("/api/admin/subscriptions/plans/{$planId}/versions/{$versionId}/publish", ['reason' => 'Launch'])->assertOk();

        $this->assignPlan($tenant, 'REASSIGN_THEN_DEL');
        $this->deleteJson("/api/admin/subscriptions/plans/{$planId}")->assertStatus(409);

        $this->assignPlan($tenant, 'STARTER');

        $this->getJson("/api/admin/subscriptions/plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.can_delete', true);

        $this->deleteJson("/api/admin/subscriptions/plans/{$planId}")->assertOk();
        $this->assertTrue(Plan::withTrashed()->findOrFail($planId)->trashed());
    }

    #[Test]
    public function only_super_admin_can_delete_plans(): void
    {
        $this->asSuperAdmin();
        $create = $this->postJson('/api/admin/subscriptions/plans', [
            'code' => 'AUTH_DELETE',
            'name' => 'Auth Delete',
            'pricing_type' => 'FREE',
        ])->assertCreated();
        $planId = (int) $create->json('data.id');

        $this->asEkklesiaAdmin();
        $this->deleteJson("/api/admin/subscriptions/plans/{$planId}")
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        $ctx = $this->asTenantAdmin();
        $this->deleteJson("/api/admin/subscriptions/plans/{$planId}")->assertForbidden();

        $this->asSuperAdmin();
        $this->assertFalse(Plan::query()->findOrFail($planId)->trashed());
        $this->deleteJson("/api/admin/subscriptions/plans/{$planId}")->assertOk();
    }

    #[Test]
    public function default_and_legacy_plans_cannot_be_deleted(): void
    {
        $this->asSuperAdmin();
        $starter = $this->plan('STARTER');

        $this->deleteJson("/api/admin/subscriptions/plans/{$starter->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', SubscriptionException::PLAN_CHANGE_NOT_ALLOWED);

        $legacy = Plan::query()->where('is_legacy', true)->first();
        if ($legacy) {
            $this->deleteJson("/api/admin/subscriptions/plans/{$legacy->id}")
                ->assertStatus(422)
                ->assertJsonPath('code', SubscriptionException::PLAN_CHANGE_NOT_ALLOWED);
        }
    }

    #[Test]
    public function open_upgrade_request_blocks_delete_until_closed(): void
    {
        $this->asSuperAdmin();
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);
        $this->assignPlan($tenant, 'STARTER');

        $create = $this->postJson('/api/admin/subscriptions/plans', [
            'code' => 'REQUEST_TARGET',
            'name' => 'Request Target',
            'pricing_type' => 'FREE',
            'is_assignable' => true,
            'is_public' => true,
        ])->assertCreated();
        $planId = (int) $create->json('data.id');
        $versionId = (int) PlanVersion::query()->where('plan_id', $planId)->value('id');
        $this->postJson("/api/admin/subscriptions/plans/{$planId}/versions/{$versionId}/publish", ['reason' => 'Launch'])->assertOk();

        $this->asTenantAdmin($tenant);
        $this->postJson('/api/tenant/subscription/upgrade-requests', [
            'plan_id' => $planId,
            'message' => 'Please upgrade',
        ])->assertCreated();

        $this->asSuperAdmin();

        $this->deleteJson("/api/admin/subscriptions/plans/{$planId}")
            ->assertStatus(409)
            ->assertJsonPath('code', SubscriptionException::PLAN_HAS_ASSIGNED_CHURCHES);

        $requestId = SubscriptionUpgradeRequest::query()->where('requested_plan_id', $planId)->value('id');
        $this->postJson("/api/admin/subscriptions/upgrade-requests/{$requestId}/reject", ['note' => 'Not now'])
            ->assertOk();

        $this->deleteJson("/api/admin/subscriptions/plans/{$planId}")->assertOk();
    }

    #[Test]
    public function deleted_plan_routes_return_not_found(): void
    {
        $this->asSuperAdmin();
        $create = $this->postJson('/api/admin/subscriptions/plans', [
            'code' => 'GONE_PLAN',
            'name' => 'Gone Plan',
            'pricing_type' => 'FREE',
        ])->assertCreated();
        $planId = (int) $create->json('data.id');
        $versionId = (int) PlanVersion::query()->where('plan_id', $planId)->value('id');

        $this->deleteJson("/api/admin/subscriptions/plans/{$planId}")->assertOk();

        $this->patchJson("/api/admin/subscriptions/plans/{$planId}", ['name' => 'Nope'])->assertNotFound();
        $this->getJson("/api/admin/subscriptions/plans/{$planId}")->assertNotFound();
        $this->getJson("/api/admin/subscriptions/plans/{$planId}/versions/{$versionId}")->assertNotFound();
    }
}
