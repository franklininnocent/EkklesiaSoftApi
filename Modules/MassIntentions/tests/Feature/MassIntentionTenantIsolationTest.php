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

class MassIntentionTenantIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function tenant_cannot_read_another_tenants_intention(): void
    {
        $tenantA = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $tenantB = Tenant::factory()->create(['features' => ['mass_intentions']]);

        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'active' => 1]);
        $this->grantPermissions($userB, ['mass.intentions.view']);

        $requestA = MassIntentionRequest::query()->create([
            'tenant_id' => $tenantA->id,
            'status' => MassIntentionStatus::OPEN,
            'beneficiary_name' => 'Cross-tenant',
            'intention_text' => 'Should not leak',
            'requested_date' => now()->addWeek()->toDateString(),
            'created_by_user_id' => User::factory()->create(['tenant_id' => $tenantA->id])->id,
        ]);

        Passport::actingAs($userB);

        $this->getJson("/api/tenant/mass-intentions/requests/{$requestA->id}")
            ->assertNotFound();
    }

    /**
     * @param  list<string>  $names
     */
    private function grantPermissions(User $user, array $names): void
    {
        $ids = Permission::query()->whereIn('name', $names)->pluck('id')->all();
        $user->permissions()->syncWithoutDetaching($ids);
        $user->clearPermissionsCache();
    }
}
