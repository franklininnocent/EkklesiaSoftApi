<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\TenantHierarchyService;
use PHPUnit\Framework\Attributes\Test;
use Modules\MassIntentions\Tests\Support\SkipsLegacyMassObligationWorkflow;
use Tests\TestCase;

class MassIntentionTransferRejectApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function receiving_parish_can_decline_pending_transfer(): void
    {
        $hierarchy = app(TenantHierarchyService::class);
        $diocese = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $hierarchy->assignHierarchy($diocese, null);
        $parishA = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $hierarchy->assignHierarchy($parishA, $diocese);
        $parishB = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $hierarchy->assignHierarchy($parishB, $diocese);

        $userA = User::factory()->create(['tenant_id' => $parishA->id, 'active' => 1]);
        $userB = User::factory()->create(['tenant_id' => $parishB->id, 'active' => 1]);
        $this->grantPermissions($userA, ['mass.intentions.view', 'mass.intentions.review']);
        $this->grantPermissions($userB, ['mass.intentions.view', 'mass.intentions.review']);

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $parishA->id,
            'status' => MassIntentionStatus::ACCEPTED,
            'beneficiary_name' => 'Stay Here',
            'intention_text' => 'Test',
            'mass_count_accepted' => 1,
            'accepted_at' => now(),
            'accepted_by_user_id' => $userA->id,
            'created_by_user_id' => $userA->id,
        ]);

        Passport::actingAs($userA);
        $transferId = $this->postJson("/api/tenant/mass-intentions/requests/{$request->id}/transfer", [
            'to_tenant_id' => $parishB->id,
        ])->json('data.id');

        Passport::actingAs($userB);
        $this->postJson("/api/tenant/mass-intentions/transfers/{$transferId}/reject", [
            'note' => 'Wrong parish',
        ])->assertOk();

        $this->assertDatabaseHas('mass_intention_transfers', [
            'id' => $transferId,
            'status' => 'rejected',
        ]);
        $this->assertDatabaseHas('mass_intention_requests', [
            'id' => $request->id,
            'tenant_id' => $parishA->id,
        ]);
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
