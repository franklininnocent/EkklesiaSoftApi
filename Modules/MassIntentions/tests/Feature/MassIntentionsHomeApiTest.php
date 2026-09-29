<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassIntentionsHomeApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function home_requires_authentication(): void
    {
        $this->getJson('/api/tenant/mass-intentions/dashboard')->assertUnauthorized();
        $this->getJson('/api/tenant/mass-intentions/home')->assertUnauthorized();
    }

    #[Test]
    public function dashboard_returns_module_summary_for_authorized_tenant_user(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/mass-intentions/dashboard');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'queue' => [
                        'open',
                        'closed',
                    ],
                    'kpis' => [
                        'open',
                        'closed',
                        'intentions_registered_this_month',
                    ],
                    'requests' => [
                        'open',
                        'closed',
                    ],
                    'period' => [
                        'intentions_registered_this_month',
                    ],
                    'trend',
                ],
            ]);
    }

    #[Test]
    public function dashboard_counts_are_tenant_scoped(): void
    {
        $tenantA = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $tenantB = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'active' => 1]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'active' => 1]);
        $this->grantPermissions($userA, ['mass.intentions.view']);
        $this->grantPermissions($userB, ['mass.intentions.view']);

        MassIntentionRequest::query()->create([
            'tenant_id' => $tenantA->id,
            'status' => MassIntentionStatus::OPEN,
            'beneficiary_name' => 'Parish A',
            'intention_text' => 'For the parish',
            'requested_date' => now()->addWeek()->toDateString(),
            'created_by_user_id' => $userA->id,
        ]);

        MassIntentionRequest::query()->create([
            'tenant_id' => $tenantB->id,
            'status' => MassIntentionStatus::CLOSED,
            'beneficiary_name' => 'Parish B',
            'intention_text' => 'Repose',
            'requested_date' => now()->subWeek()->toDateString(),
            'closed_at' => now(),
            'close_source' => 'manual',
            'created_by_user_id' => $userB->id,
        ]);

        Passport::actingAs($userA);
        $forA = $this->getJson('/api/tenant/mass-intentions/dashboard')->assertOk();
        $this->assertSame(1, $forA->json('data.queue.open'));
        $this->assertSame(0, $forA->json('data.queue.closed'));

        Passport::actingAs($userB);
        $forB = $this->getJson('/api/tenant/mass-intentions/dashboard')->assertOk();
        $this->assertSame(0, $forB->json('data.queue.open'));
        $this->assertSame(1, $forB->json('data.queue.closed'));
    }

    #[Test]
    public function dashboard_is_forbidden_without_view_permission(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);

        Passport::actingAs($user);

        $this->getJson('/api/tenant/mass-intentions/dashboard')->assertForbidden();
    }

    #[Test]
    public function dashboard_and_home_return_the_same_snapshot(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        Passport::actingAs($user);

        $dashboard = $this->getJson('/api/tenant/mass-intentions/dashboard')->assertOk()->json('data');
        $home = $this->getJson('/api/tenant/mass-intentions/home')->assertOk()->json('data');

        $this->assertSame($home, $dashboard);
    }

    /**
     * @param  list<string>  $names
     */
    private function grantPermissions(User $user, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $permission = Permission::query()->where('name', $name)->firstOrFail();
            $ids[] = $permission->id;
        }

        $user->permissions()->syncWithoutDetaching($ids);
        $user->clearPermissionsCache();
        if (method_exists($user, 'clearRequestPermissionCache')) {
            $user->clearRequestPermissionCache();
        }
    }
}
