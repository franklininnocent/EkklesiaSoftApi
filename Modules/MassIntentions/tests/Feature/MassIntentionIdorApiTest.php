<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassIntentionOffering;
use Modules\MassIntentions\Models\MassIntentionOfferingReceipt;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassIntentionIdorApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function tenant_cannot_open_another_tenants_mass_workspace(): void
    {
        $tenantA = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $tenantB = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'active' => 1]);
        $this->grantPermissions($userB, ['mass.intentions.view']);

        $celebration = MassCelebration::query()->create([
            'tenant_id' => $tenantA->id,
            'celebrated_on' => now()->toDateString(),
            'status' => 'scheduled',
            'created_by_user_id' => User::factory()->create(['tenant_id' => $tenantA->id])->id,
        ]);

        Passport::actingAs($userB);

        $this->getJson("/api/tenant/mass-intentions/celebrations/{$celebration->id}/workspace")
            ->assertNotFound();
    }

    #[Test]
    public function tenant_cannot_void_another_tenants_receipt(): void
    {
        $tenantA = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $tenantB = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'active' => 1]);
        $this->grantPermissions($userB, ['mass.intentions.view', 'mass.intentions.offerings.record']);

        $actorA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenantA->id,
            'status' => MassIntentionStatus::ACCEPTED,
            'beneficiary_name' => 'Other parish',
            'intention_text' => 'Test',
            'created_by_user_id' => $actorA->id,
        ]);
        $offering = MassIntentionOffering::query()->create([
            'tenant_id' => $tenantA->id,
            'request_id' => $request->id,
            'currency_code' => 'USD',
            'status' => 'open',
        ]);
        $receipt = MassIntentionOfferingReceipt::query()->create([
            'tenant_id' => $tenantA->id,
            'offering_id' => $offering->id,
            'receipt_number' => 'MI-2026-0001',
            'amount' => '10.00',
            'payment_method' => 'cash',
            'received_on' => now()->toDateString(),
            'recorded_by_user_id' => $actorA->id,
        ]);

        Passport::actingAs($userB);

        $this->postJson("/api/tenant/mass-intentions/receipts/{$receipt->id}/void", [
            'reason' => 'Attempted cross-tenant void',
        ])->assertNotFound();
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
