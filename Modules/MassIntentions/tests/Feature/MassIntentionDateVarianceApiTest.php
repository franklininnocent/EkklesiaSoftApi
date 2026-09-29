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

class MassIntentionDateVarianceApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function schedule_requires_reason_when_kept_date_differs_from_mass_day(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);

        $requested = now()->addDays(3)->toDateString();
        $massDay = now()->addWeek()->toDateString();

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::ACCEPTED,
            'beneficiary_name' => 'Anna',
            'intention_text' => 'Health',
            'requested_date' => $requested,
            'date_must_be_kept' => true,
            'mass_count_accepted' => 1,
            'created_by_user_id' => $user->id,
        ]);

        MassIntentionObligation::query()->create([
            'tenant_id' => $tenant->id,
            'request_id' => $request->id,
            'sequence' => 1,
            'status' => MassObligationStatus::PENDING,
        ]);

        $celebration = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => $massDay,
            'status' => 'scheduled',
            'created_by_user_id' => $user->id,
        ]);

        Passport::actingAs($user);

        $this->postJson("/api/tenant/mass-intentions/requests/{$request->id}/schedule", [
            'celebration_id' => $celebration->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['date_variance_reason']);

        $this->postJson("/api/tenant/mass-intentions/requests/{$request->id}/schedule", [
            'celebration_id' => $celebration->id,
            'date_variance_reason' => 'Priest agreed to move to Sunday parish Mass.',
        ])->assertOk();

        $this->assertDatabaseHas('mass_intention_assignments', [
            'celebration_id' => $celebration->id,
            'date_variance_reason' => 'Priest agreed to move to Sunday parish Mass.',
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
