<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Subscriptions\Models\PlanEntitlement;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class MassIntentionCatalogEntitlementTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function starter_church_cannot_use_mass_intentions_until_the_plan_includes_it(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->grantMassIntentionsView($ctx['user']);
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->useEntitlementEngine('enforce');

        Passport::actingAs($ctx['user']->fresh());
        $this->getJson('/api/tenant/mass-intentions/home')
            ->assertForbidden()
            ->assertJsonPath('success', false);
        $this->getJson('/api/tenant/entitlements')
            ->assertOk()
            ->assertJsonPath('data.features.MASS_INTENTIONS', false);
        $this->getJson('/api/tenant/mass-intentions/module-status')
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->enableMassIntentionsOnPlan('STANDARD');
        $this->assignPlan($ctx['tenant']->fresh(), 'STANDARD');
        app(EntitlementResolver::class)->forget((int) $ctx['tenant']->id);

        Passport::actingAs($ctx['user']->fresh());
        $this->getJson('/api/tenant/entitlements')
            ->assertOk()
            ->assertJsonPath('data.features.MASS_INTENTIONS', true);
        $this->getJson('/api/tenant/mass-intentions/module-status')
            ->assertOk()
            ->assertJsonPath('data.enabled', true);
        $this->getJson('/api/tenant/mass-intentions/home')->assertOk();
    }

    #[Test]
    public function enabling_mass_intentions_on_one_plan_does_not_open_it_for_other_plans(): void
    {
        $this->enableMassIntentionsOnPlan('STANDARD');

        $starter = $this->asTenantAdmin();
        $this->grantMassIntentionsView($starter['user']);
        $this->assignPlan($starter['tenant'], 'STARTER');
        $this->useEntitlementEngine('enforce');
        Passport::actingAs($starter['user']->fresh());
        $this->getJson('/api/tenant/mass-intentions/home')->assertForbidden();

        $standard = $this->asTenantAdmin();
        $this->grantMassIntentionsView($standard['user']);
        $this->assignPlan($standard['tenant'], 'STANDARD');
        $this->useEntitlementEngine('enforce');
        Passport::actingAs($standard['user']->fresh());
        $this->getJson('/api/tenant/mass-intentions/home')->assertOk();
    }

    private function enableMassIntentionsOnPlan(string $planCode): void
    {
        $this->asSuperAdmin();
        $plan = $this->plan($planCode);
        $draftId = (int) $this->postJson("/api/admin/subscriptions/plans/{$plan->id}/versions", [])
            ->assertCreated()
            ->json('data.id');

        $draft = PlanVersion::query()->with('entitlements.feature')->findOrFail($draftId);
        $payload = $draft->entitlements->map(static function (PlanEntitlement $row): array {
            $code = (string) $row->feature?->code;

            return [
                'feature_code' => $code,
                'is_enabled' => $code === 'MASS_INTENTIONS' ? true : (bool) $row->is_enabled,
                'numeric_value' => $row->numeric_value,
                'tier_value' => $row->tier_value,
            ];
        })->values()->all();

        $this->assertTrue(collect($payload)->contains(fn (array $row) => $row['feature_code'] === 'MASS_INTENTIONS'));

        $this->putJson("/api/admin/subscriptions/plans/{$plan->id}/versions/{$draftId}/entitlements", [
            'entitlements' => $payload,
        ])->assertOk();

        $this->postJson("/api/admin/subscriptions/plans/{$plan->id}/versions/{$draftId}/publish", [
            'reason' => 'Enable Mass Intentions for this plan',
        ])->assertOk();
    }

    private function grantMassIntentionsView($user): void
    {
        $id = Permission::query()->where('name', 'mass.intentions.view')->value('id');
        $this->assertNotNull($id);
        $user->permissions()->syncWithoutDetaching([$id]);
        $user->clearPermissionsCache();
        $user->clearRequestPermissionCache();
    }
}
