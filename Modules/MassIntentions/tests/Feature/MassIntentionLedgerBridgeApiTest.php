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
use Modules\MassIntentions\Tests\Support\SkipsLegacyMassObligationWorkflow;
use Tests\TestCase;

class MassIntentionLedgerBridgeApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function ledger_bridge_lists_non_voided_receipts(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, [
            'mass.intentions.view',
            'mass.intentions.create',
            'mass.intentions.review',
            'mass.intentions.offerings.record',
            'mass.intentions.offerings.view',
            'mass.intentions.register.export',
        ]);

        Passport::actingAs($user);

        $create = $this->postJson('/api/tenant/mass-intentions/requests', [
            'beneficiary_name' => 'Ledger',
            'intention_text' => 'Offering',
            'mass_count' => 1,
        ]);
        $requestId = $create->json('data.id');

        $this->postJson("/api/tenant/mass-intentions/requests/{$requestId}/accept", [
            'mass_count' => 1,
            'offering_amount' => '25.00',
            'offering_payment_method' => 'cash',
        ])->assertOk();

        $response = $this->getJson('/api/tenant/mass-intentions/reports/donations-ledger-bridge');
        $response->assertOk()
            ->assertJsonPath('meta.format', 'mass_intentions_ledger_bridge_v1');

        $this->assertNotEmpty($response->json('data'));
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
