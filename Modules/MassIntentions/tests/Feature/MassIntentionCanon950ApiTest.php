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

class MassIntentionCanon950ApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function accept_creates_exactly_n_obligations_in_one_transaction(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.review']);

        Passport::actingAs($user);

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::PENDING_REVIEW,
            'beneficiary_name' => 'Canon 950',
            'intention_text' => 'Thanksgiving',
            'mass_count_requested' => 3,
            'created_by_user_id' => $user->id,
        ]);

        $this->postJson("/api/tenant/mass-intentions/requests/{$request->id}/accept", [
            'mass_count' => 3,
        ])->assertOk();

        $this->assertDatabaseCount('mass_intention_obligations', 3);
        foreach ([1, 2, 3] as $sequence) {
            $this->assertDatabaseHas('mass_intention_obligations', [
                'request_id' => $request->id,
                'sequence' => $sequence,
            ]);
        }
        $this->assertDatabaseHas('mass_intention_audits', [
            'tenant_id' => $tenant->id,
            'event_type' => 'request.accepted',
            'request_id' => $request->id,
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
