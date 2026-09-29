<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Support\MassObligationStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Modules\MassIntentions\Tests\Support\SkipsLegacyMassObligationWorkflow;
use Tests\TestCase;

class MassIntentionIncreaseMassCountApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function increasing_mass_count_after_accept_adds_pending_obligations_only(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create', 'mass.intentions.review']);

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::ACCEPTED,
            'beneficiary_name' => 'Rita',
            'intention_text' => 'Thanks',
            'mass_count_accepted' => 1,
            'mass_count_requested' => 1,
            'created_by_user_id' => $user->id,
        ]);

        $request->obligations()->create([
            'tenant_id' => $tenant->id,
            'sequence' => 1,
            'status' => MassObligationStatus::SAID,
        ]);

        Passport::actingAs($user);

        $this->putJson("/api/tenant/mass-intentions/requests/{$request->id}", [
            'mass_count' => 3,
        ])->assertOk()
            ->assertJsonPath('data.mass_count_accepted', 3);

        $this->assertDatabaseCount('mass_intention_obligations', 3);
        $this->assertDatabaseHas('mass_intention_obligations', [
            'request_id' => $request->id,
            'sequence' => 1,
            'status' => MassObligationStatus::SAID,
        ]);
        $this->assertDatabaseHas('mass_intention_obligations', [
            'request_id' => $request->id,
            'sequence' => 3,
            'status' => MassObligationStatus::PENDING,
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
