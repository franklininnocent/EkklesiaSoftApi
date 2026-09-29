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

class MassIntentionCelebrantOverrideApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function confirm_said_stores_celebrant_override_per_obligation(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.fulfil']);

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::ACCEPTED,
            'beneficiary_name' => 'Test',
            'intention_text' => 'Repose',
            'mass_count_accepted' => 1,
            'created_by_user_id' => $user->id,
        ]);

        $obligation = MassIntentionObligation::query()->create([
            'tenant_id' => $tenant->id,
            'request_id' => $request->id,
            'sequence' => 1,
            'status' => MassObligationStatus::SCHEDULED,
        ]);

        $celebration = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => now()->toDateString(),
            'status' => 'scheduled',
            'created_by_user_id' => $user->id,
        ]);

        MassIntentionAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'obligation_id' => $obligation->id,
            'celebration_id' => $celebration->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $user->id,
        ]);

        Passport::actingAs($user);

        $this->postJson("/api/tenant/mass-intentions/celebrations/{$celebration->id}/confirm-said", [
            'obligation_ids' => [$obligation->id],
            'celebrant_overrides' => [
                $obligation->id => 'Fr. Guest Celebrant',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('mass_intention_fulfilments', [
            'obligation_id' => $obligation->id,
            'celebrant_override' => 'Fr. Guest Celebrant',
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
