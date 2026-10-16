<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassCelebrationOrigin;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassCelebrationApplyScheduleProposalApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function apply_schedule_proposal_updates_time_on_same_mass(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);

        Passport::actingAs($user);

        $celebration = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'origin' => MassCelebrationOrigin::REGULAR,
            'slot_id' => fake()->uuid(),
            'celebrated_on' => Carbon::now()->addWeek(),
            'celebrated_at' => '06:00',
            'place' => 'Main',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        $this->postJson("/api/tenant/mass-intentions/celebrations/{$celebration->id}/apply-schedule-proposal", [
            'celebrated_at' => '07:00',
            'place' => 'Main',
        ])
            ->assertOk()
            ->assertJsonPath('data.celebrated_at', '07:00');

        $this->assertStringStartsWith('07:00', (string) $celebration->fresh()->celebrated_at);
    }

    private function grantPermissions(User $user, array $permissionNames): void
    {
        $ids = Permission::query()->whereIn('name', $permissionNames)->pluck('id');
        $user->permissions()->syncWithoutDetaching($ids);
    }
}
