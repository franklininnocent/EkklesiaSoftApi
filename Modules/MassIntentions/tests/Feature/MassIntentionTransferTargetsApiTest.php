<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\TenantHierarchyService;
use PHPUnit\Framework\Attributes\Test;
use Modules\MassIntentions\Tests\Support\SkipsLegacyMassObligationWorkflow;
use Tests\TestCase;

class MassIntentionTransferTargetsApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function lists_sibling_parishes_with_mass_intentions_enabled(): void
    {
        $hierarchy = app(TenantHierarchyService::class);
        $diocese = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $hierarchy->assignHierarchy($diocese, null);

        $parishA = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $hierarchy->assignHierarchy($parishA, $diocese);
        $parishB = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD', 'name' => 'St. B Parish']);
        $hierarchy->assignHierarchy($parishB, $diocese);

        $user = User::factory()->create(['tenant_id' => $parishA->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.review']);

        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/mass-intentions/transfers/targets');
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('tenant_id')->all();
        $this->assertContains($parishB->id, $ids);
        $this->assertNotContains($parishA->id, $ids);
    }

    /**
     * @param  list<string>  $names
     */
    private function grantPermissions(User $user, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $ids[] = Permission::query()->where('name', $name)->firstOrFail()->id;
        }
        $user->permissions()->syncWithoutDetaching($ids);
        $user->clearPermissionsCache();
    }
}
