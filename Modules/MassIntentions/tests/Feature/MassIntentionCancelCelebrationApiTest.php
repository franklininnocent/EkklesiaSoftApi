<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassIntentionAssignment;
use Modules\MassIntentions\Models\MassIntentionObligation;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Support\MassObligationStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Modules\MassIntentions\Tests\Support\SkipsLegacyMassObligationWorkflow;
use Tests\TestCase;

class MassIntentionCancelCelebrationApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function cancel_mass_reassigns_one_obligation_and_releases_the_other(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::ACCEPTED,
            'beneficiary_name' => 'Test Person',
            'intention_text' => 'Repose',
            'mass_count_accepted' => 2,
            'created_by_user_id' => $user->id,
        ]);

        $obligationA = MassIntentionObligation::query()->create([
            'tenant_id' => $tenant->id,
            'request_id' => $request->id,
            'sequence' => 1,
            'status' => MassObligationStatus::SCHEDULED,
        ]);
        $obligationB = MassIntentionObligation::query()->create([
            'tenant_id' => $tenant->id,
            'request_id' => $request->id,
            'sequence' => 2,
            'status' => MassObligationStatus::SCHEDULED,
        ]);

        $cancelledMass = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
            'created_by_user_id' => $user->id,
        ]);
        $targetMass = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => now()->addDays(2)->toDateString(),
            'status' => 'scheduled',
            'created_by_user_id' => $user->id,
        ]);

        foreach ([$obligationA, $obligationB] as $obligation) {
            MassIntentionAssignment::query()->create([
                'tenant_id' => $tenant->id,
                'obligation_id' => $obligation->id,
                'celebration_id' => $cancelledMass->id,
                'assigned_at' => now(),
                'assigned_by_user_id' => $user->id,
            ]);
        }

        Passport::actingAs($user);

        $response = $this->postJson("/api/tenant/mass-intentions/celebrations/{$cancelledMass->id}/cancel", [
            'reason' => 'Priest unavailable',
            'reassignments' => [
                ['obligation_id' => $obligationA->id, 'celebration_id' => $targetMass->id],
                ['obligation_id' => $obligationB->id, 'celebration_id' => null],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('mass_celebrations', [
            'id' => $cancelledMass->id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('mass_intention_obligations', [
            'id' => $obligationA->id,
            'status' => MassObligationStatus::SCHEDULED,
        ]);
        $this->assertDatabaseHas('mass_intention_obligations', [
            'id' => $obligationB->id,
            'status' => MassObligationStatus::PENDING,
        ]);
        $this->assertDatabaseHas('mass_intention_assignments', [
            'obligation_id' => $obligationA->id,
            'celebration_id' => $targetMass->id,
            'unassigned_at' => null,
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
