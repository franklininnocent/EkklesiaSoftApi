<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassSchedule;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassScheduleSecurityApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function tenant_cannot_mutate_another_tenants_schedule(): void
    {
        $tenantA = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $tenantB = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'active' => 1]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'active' => 1]);
        $this->grantPermissions($userA, ['mass.intentions.view', 'mass.intentions.schedule']);
        $this->grantPermissions($userB, ['mass.intentions.view', 'mass.intentions.schedule']);

        Passport::actingAs($userA);
        $scheduleId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.schedule.id');

        Passport::actingAs($userB);
        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'slots' => [['weekday' => 1, 'celebrated_at' => '08:00', 'place_source' => 'unset', 'celebrant_source' => 'unset']],
        ])->assertNotFound();

        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", [
            'apply_from' => now()->toDateString(),
        ])->assertNotFound();
    }

    #[Test]
    public function view_only_user_cannot_save_schedule_draft(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        Passport::actingAs($user);
        $scheduleId = MassSchedule::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Regular Mass schedule',
            'kind' => 'regular',
            'status' => 'active',
            'created_by_user_id' => $user->id,
        ])->id;

        $this->getJson('/api/tenant/mass-intentions/schedules/regular')->assertOk();

        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'slots' => [],
        ])->assertForbidden();
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
