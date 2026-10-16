<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassScheduleLifecycleApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
        Carbon::setTestNow('2026-06-01 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function temporary_schedule_can_be_inactivated_and_revision_history_lists(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $created = $this->postJson('/api/tenant/mass-intentions/schedules/temporaries', [
            'name' => 'Summer hours',
            'coverage_mode' => 'full_week',
        ])->assertCreated()
            ->json('data.schedule.id');

        $this->postJson("/api/tenant/mass-intentions/schedules/{$created}/inactivate")
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->postJson("/api/tenant/mass-intentions/schedules/{$created}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->getJson("/api/tenant/mass-intentions/schedules/{$created}/revisions")
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'revision_number', 'status']]]);
    }

    #[Test]
    public function temporaries_list_can_include_inactive(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $created = $this->postJson('/api/tenant/mass-intentions/schedules/temporaries', [
            'name' => 'To inactivate',
            'coverage_mode' => 'full_week',
        ])->assertCreated()
            ->json('data.schedule.id');

        $this->postJson("/api/tenant/mass-intentions/schedules/{$created}/inactivate")->assertOk();

        $this->getJson('/api/tenant/mass-intentions/schedules/temporaries')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/tenant/mass-intentions/schedules/temporaries?include_inactive=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.schedule.status', 'inactive');
    }

    #[Test]
    public function regular_schedule_cannot_be_inactivated(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $scheduleId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.schedule.id');

        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/inactivate")
            ->assertStatus(422);
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
