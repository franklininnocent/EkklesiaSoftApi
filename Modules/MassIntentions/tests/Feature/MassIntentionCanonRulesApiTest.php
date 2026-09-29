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

class MassIntentionCanonRulesApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function collective_accept_blocked_without_provincial_authorization(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.review']);

        Passport::actingAs($user);

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::PENDING_REVIEW,
            'beneficiary_name' => 'Collective',
            'intention_text' => 'Thanksgiving',
            'is_collective' => true,
            'mass_count_requested' => 1,
            'created_by_user_id' => $user->id,
        ]);

        $this->postJson("/api/tenant/mass-intentions/requests/{$request->id}/accept", [
            'mass_count' => 1,
        ])->assertStatus(422);
    }

    #[Test]
    public function schedule_beyond_one_year_after_accept_is_rejected(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, [
            'mass.intentions.view',
            'mass.intentions.review',
            'mass.intentions.schedule',
        ]);

        Passport::actingAs($user);

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::PENDING_REVIEW,
            'beneficiary_name' => 'Late Mass',
            'intention_text' => 'Thanksgiving',
            'mass_count_requested' => 1,
            'created_by_user_id' => $user->id,
        ]);

        $this->postJson("/api/tenant/mass-intentions/requests/{$request->id}/accept", [
            'mass_count' => 1,
        ])->assertOk();

        $request->refresh();
        $request->accepted_at = now()->subYears(2);
        $request->save();

        $obligationId = $this->getJson('/api/tenant/mass-intentions/obligations/pending-schedule')
            ->json('data.0.obligation_id');

        $celebrationId = $this->postJson('/api/tenant/mass-intentions/celebrations', [
            'celebrated_on' => now()->toDateString(),
        ])->json('data.id');

        $this->postJson("/api/tenant/mass-intentions/celebrations/{$celebrationId}/assign-obligations", [
            'obligation_ids' => [$obligationId],
        ])->assertStatus(422);
    }

    #[Test]
    public function transfer_blocked_when_donor_prohibits_transfer(): void
    {
        $hierarchy = app(TenantHierarchyService::class);
        $diocese = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $hierarchy->assignHierarchy($diocese, null);

        $parishA = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $hierarchy->assignHierarchy($parishA, $diocese);
        $parishB = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $hierarchy->assignHierarchy($parishB, $diocese);

        $user = User::factory()->create(['tenant_id' => $parishA->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.review']);

        Passport::actingAs($user);

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $parishA->id,
            'status' => MassIntentionStatus::ACCEPTED,
            'beneficiary_name' => 'No transfer',
            'intention_text' => 'Thanksgiving',
            'prohibit_transfer' => true,
            'mass_count_accepted' => 1,
            'accepted_at' => now(),
            'accepted_by_user_id' => $user->id,
            'created_by_user_id' => $user->id,
        ]);

        $this->postJson("/api/tenant/mass-intentions/requests/{$request->id}/transfer", [
            'to_tenant_id' => $parishB->id,
        ])->assertStatus(422);
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
