<?php

namespace Modules\Subscriptions\Tests\Concerns;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Subscriptions\Services\SubscriptionPlanChangeService;
use Modules\Subscriptions\Support\SubscriptionsPermissionCatalog;
use Modules\Tenants\Models\Tenant;

trait InteractsWithSubscriptions
{
    protected function useEntitlementEngine(string $mode): void
    {
        config(['subscriptions.entitlement_engine' => $mode]);
        app(EntitlementResolver::class)->flushMemo();
    }

    protected function plan(string $code): Plan
    {
        return Plan::query()->where('code', $code)->firstOrFail();
    }

    /**
     * Platform Ekklesia Admin with the default operate-only permission set.
     */
    protected function asEkklesiaAdmin(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => Role::EKKLESIA_ADMIN, 'tenant_id' => null],
            [
                'description' => 'Ekklesia Admin',
                'level' => Role::LEVEL_EKKLESIA_ADMIN,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );
        SubscriptionsPermissionCatalog::syncPermissionsAndRoles();
        User::flushRequestPermissionCache();

        $user = User::factory()->create([
            'user_type' => null,
            'tenant_id' => null,
            'is_primary_admin' => false,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);
        $user->clearRequestPermissionCache();
        $user->clearPermissionsCache();
        Passport::actingAs($user->fresh());

        return $user->fresh();
    }

    protected function assignPlan(Tenant $tenant, string $code, array $options = []): TenantSubscription
    {
        return app(SubscriptionPlanChangeService::class)->assign($tenant, $this->plan($code), $options + [
            'reason' => 'Test assignment',
            'confirm_impact' => true,
        ]);
    }

    protected function currentSubscription(Tenant $tenant): ?TenantSubscription
    {
        return TenantSubscription::query()->forTenant((int) $tenant->id)->current()->first();
    }
}
