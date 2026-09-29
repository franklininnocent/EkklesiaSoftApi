<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassIntentionOffering;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Modules\MassIntentions\Tests\Support\SkipsLegacyMassObligationWorkflow;
use Tests\TestCase;

class MassIntentionOfferingApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function two_receipts_can_belong_to_one_accepted_intention(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, [
            'mass.intentions.view',
            'mass.intentions.create',
            'mass.intentions.review',
            'mass.intentions.offerings.record',
        ]);

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::ACCEPTED,
            'beneficiary_name' => 'Paul',
            'intention_text' => 'Repose',
            'mass_count_accepted' => 1,
            'created_by_user_id' => $user->id,
        ]);

        MassIntentionOffering::query()->create([
            'tenant_id' => $tenant->id,
            'request_id' => $request->id,
            'currency_code' => 'USD',
            'status' => 'open',
        ]);

        Passport::actingAs($user);

        $this->postJson("/api/tenant/mass-intentions/requests/{$request->id}/receipts", [
            'amount' => '10.00',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->postJson("/api/tenant/mass-intentions/requests/{$request->id}/receipts", [
            'amount' => '5.00',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertDatabaseCount('mass_intention_offering_receipts', 2);
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
