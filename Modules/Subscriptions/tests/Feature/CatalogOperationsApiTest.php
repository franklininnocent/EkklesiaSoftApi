<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanEntitlement;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class CatalogOperationsApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function super_admin_duplicates_a_plan_into_a_private_draft_without_touching_the_source(): void
    {
        $this->asSuperAdmin();
        $source = $this->plan('STANDARD');
        $sourceCount = $source->activeVersion->entitlements()->count();

        $response = $this->postJson("/api/admin/subscriptions/plans/{$source->id}/duplicate", [
            'code' => 'standard_plus',
            'name' => 'Standard Plus',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'STANDARD_PLUS')
            ->assertJsonPath('data.status', Plan::STATUS_DRAFT)
            ->assertJsonPath('data.is_public', false);

        $copy = Plan::query()->findOrFail($response->json('data.id'));
        $draft = PlanVersion::query()->where('plan_id', $copy->id)->firstOrFail();
        $this->assertSame(PlanVersion::STATUS_DRAFT, $draft->status);
        $this->assertSame($sourceCount, $draft->entitlements()->count());
        $this->assertSame((string) $source->activeVersion->monthly_price, (string) $draft->monthly_price);
        $this->assertSame('Standard', $source->fresh()->name);

        $this->postJson("/api/admin/subscriptions/plans/{$source->id}/duplicate", ['code' => 'LEGACY_X', 'name' => 'Nope'])
            ->assertStatus(422);
    }

    #[Test]
    public function operate_only_ekklesia_admin_cannot_duplicate_but_can_list_plan_tenants(): void
    {
        $tenant = $this->activeTenant(['name' => 'St. Thomas Parish']);
        $this->assignPlan($tenant, 'STARTER');
        $this->asEkklesiaAdmin();
        $starter = $this->plan('STARTER');

        $this->postJson("/api/admin/subscriptions/plans/{$starter->id}/duplicate", ['code' => 'COPY', 'name' => 'Copy'])
            ->assertForbidden();

        $this->getJson("/api/admin/subscriptions/plans/{$starter->id}/tenants?search=thomas")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tenant_id', $tenant->id)
            ->assertJsonPath('data.0.version_number', 1);

        $this->getJson("/api/admin/subscriptions/plans/{$starter->id}/tenants?search=nomatch")
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function tenant_admin_cannot_reach_catalog_operations(): void
    {
        $this->asTenantAdmin();
        $starter = $this->plan('STARTER');
        $version = $starter->activeVersion;

        $this->postJson("/api/admin/subscriptions/plans/{$starter->id}/duplicate", ['code' => 'MINE', 'name' => 'Mine'])->assertForbidden();
        $this->getJson("/api/admin/subscriptions/plans/{$starter->id}/tenants")->assertForbidden();
        $this->getJson("/api/admin/subscriptions/plans/{$starter->id}/versions/{$version->id}/impact")->assertForbidden();
        $this->postJson("/api/admin/subscriptions/plans/{$starter->id}/versions/{$version->id}/migrate-tenants", ['reason' => 'Self migrate'])
            ->assertForbidden();
    }

    #[Test]
    public function version_preview_shows_modules_limits_and_pricing(): void
    {
        $this->asSuperAdmin();
        $starter = $this->plan('STARTER');
        $version = $starter->activeVersion;

        $data = $this->getJson("/api/admin/subscriptions/plans/{$starter->id}/versions/{$version->id}/preview")
            ->assertOk()
            ->assertJsonPath('data.card.code', 'STARTER')
            ->assertJsonPath('data.card.monthly_price', '1499.00')
            ->json('data');

        $modules = collect($data['modules'])->keyBy('code');
        $this->assertTrue($modules['FAMILIES']['enabled']);
        $this->assertTrue($modules['FAMILIES']['is_core']);
        $this->assertSame(250, collect($data['limits'])->firstWhere('code', 'PEOPLE_LIMIT')['value']);

        $standardVersion = $this->plan('STANDARD')->activeVersion;
        $this->getJson("/api/admin/subscriptions/plans/{$starter->id}/versions/{$standardVersion->id}/preview")->assertNotFound();
    }

    #[Test]
    public function new_version_impact_and_tenant_migration_require_confirmation_and_never_delete_data(): void
    {
        $tenant = $this->activeTenant();
        $this->assignPlan($tenant, 'STARTER');
        $family = Family::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        FamilyMember::factory()->count(2)->create(['family_id' => $family->id, 'status' => 'active']);
        $v1 = $this->currentSubscription($tenant)->plan_version_id;

        $this->asSuperAdmin();
        $starter = $this->plan('STARTER');
        $v2 = $this->publishLowerPeopleLimitVersion($starter, 1);

        $impact = $this->getJson("/api/admin/subscriptions/plans/{$starter->id}/versions/{$v2->id}/impact")
            ->assertOk()
            ->assertJsonPath('data.tenants_on_other_versions', 1)
            ->assertJsonPath('data.tenants_over_new_limits', 1)
            ->assertJsonPath('data.requires_confirmation', true)
            ->json('data');
        $this->assertSame('PEOPLE_LIMIT', $impact['groups'][0]['over_limit'][0]['code']);

        $url = "/api/admin/subscriptions/plans/{$starter->id}/versions/{$v2->id}/migrate-tenants";

        $this->postJson($url, ['reason' => 'Preview first', 'dry_run' => true])
            ->assertOk()
            ->assertJsonPath('data.preview.0.requires_confirmation', true)
            ->assertJsonPath('data.migrated', []);
        $this->assertSame($v1, $this->currentSubscription($tenant)->plan_version_id);

        $this->postJson($url, ['reason' => 'Move to v2'])
            ->assertOk()
            ->assertJsonPath('data.migrated', [])
            ->assertJsonPath('data.skipped.0.code', SubscriptionException::PLAN_CHANGE_REQUIRES_CONFIRMATION);
        $this->assertSame($v1, $this->currentSubscription($tenant)->plan_version_id);

        $this->postJson($url, ['reason' => 'Move to v2', 'confirm_impact' => true])
            ->assertOk()
            ->assertJsonPath('data.migrated', [$tenant->id])
            ->assertJsonPath('data.remaining', 0);

        $current = $this->currentSubscription($tenant);
        $this->assertSame($v2->id, $current->plan_version_id);
        $this->assertSame(TenantSubscription::SOURCE_VERSION_MIGRATION, $current->source);
        $this->assertSame(TenantSubscription::RECORD_SUPERSEDED, TenantSubscription::query()->where('plan_version_id', $v1)->where('tenant_id', $tenant->id)->value('record_status'));
        $this->assertSame(2, FamilyMember::query()->where('family_id', $family->id)->count());
    }

    #[Test]
    public function tenants_can_only_be_migrated_to_the_active_version(): void
    {
        $this->asSuperAdmin();
        $starter = $this->plan('STARTER');
        $draftId = $this->postJson("/api/admin/subscriptions/plans/{$starter->id}/versions", [])->assertCreated()->json('data.id');

        $this->postJson("/api/admin/subscriptions/plans/{$starter->id}/versions/{$draftId}/migrate-tenants", ['reason' => 'Too early'])
            ->assertStatus(422)
            ->assertJsonPath('code', SubscriptionException::PLAN_VERSION_NOT_ACTIVE);
    }

    private function publishLowerPeopleLimitVersion(Plan $plan, int $peopleLimit): PlanVersion
    {
        $draftId = $this->postJson("/api/admin/subscriptions/plans/{$plan->id}/versions", [])->assertCreated()->json('data.id');
        PlanEntitlement::query()
            ->where('plan_version_id', $draftId)
            ->where('feature_id', Feature::query()->where('code', 'PEOPLE_LIMIT')->value('id'))
            ->update(['numeric_value' => $peopleLimit]);
        $this->postJson("/api/admin/subscriptions/plans/{$plan->id}/versions/{$draftId}/publish", ['reason' => 'Tighter limit'])
            ->assertOk()
            ->assertJsonPath('data.status', PlanVersion::STATUS_ACTIVE);

        return PlanVersion::query()->findOrFail($draftId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeTenant(array $attributes = []): Tenant
    {
        return Tenant::factory()->active()->create($attributes + [
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);
    }
}
