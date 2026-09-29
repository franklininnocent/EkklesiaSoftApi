<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassIntentionObligation;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Support\MassObligationStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Modules\MassIntentions\Tests\Support\SkipsLegacyMassObligationWorkflow;
use Tests\TestCase;

class MassIntentionScheduleApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function schedule_assigns_first_pending_obligation(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::ACCEPTED,
            'beneficiary_name' => 'Anna',
            'intention_text' => 'Health',
            'mass_count_accepted' => 1,
            'created_by_user_id' => $user->id,
        ]);

        $obligation = MassIntentionObligation::query()->create([
            'tenant_id' => $tenant->id,
            'request_id' => $request->id,
            'sequence' => 1,
            'status' => MassObligationStatus::PENDING,
        ]);

        $celebration = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => now()->addWeek()->toDateString(),
            'status' => 'scheduled',
            'created_by_user_id' => $user->id,
        ]);

        Passport::actingAs($user);

        $response = $this->postJson("/api/tenant/mass-intentions/requests/{$request->id}/schedule", [
            'celebration_id' => $celebration->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('mass_intention_obligations', [
            'id' => $obligation->id,
            'status' => MassObligationStatus::SCHEDULED,
        ]);
        $this->assertDatabaseHas('mass_intention_assignments', [
            'obligation_id' => $obligation->id,
            'celebration_id' => $celebration->id,
        ]);
    }

    #[Test]
    public function assign_obligations_endpoint_schedules_multiple(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);

        $celebration = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => now()->addWeek()->toDateString(),
            'status' => 'scheduled',
            'created_by_user_id' => $user->id,
        ]);

        $obligationIds = [];
        foreach (['A', 'B'] as $name) {
            $request = MassIntentionRequest::query()->create([
                'tenant_id' => $tenant->id,
                'status' => MassIntentionStatus::ACCEPTED,
                'beneficiary_name' => $name,
                'intention_text' => 'Thanks',
                'mass_count_accepted' => 1,
                'created_by_user_id' => $user->id,
            ]);
            $obligationIds[] = MassIntentionObligation::query()->create([
                'tenant_id' => $tenant->id,
                'request_id' => $request->id,
                'sequence' => 1,
                'status' => MassObligationStatus::PENDING,
            ])->id;
        }

        Passport::actingAs($user);

        $response = $this->postJson("/api/tenant/mass-intentions/celebrations/{$celebration->id}/assign-obligations", [
            'obligation_ids' => $obligationIds,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.intentions', fn ($rows) => count($rows) === 2);
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
