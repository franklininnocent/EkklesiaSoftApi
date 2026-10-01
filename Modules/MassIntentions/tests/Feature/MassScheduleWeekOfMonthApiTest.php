<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassScheduleWeekOfMonthApiTest extends TestCase
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
    public function apply_materializes_second_and_fourth_sunday_only(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);

        Passport::actingAs($user);

        $scheduleId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')
            ->assertOk()
            ->json('data.schedule.id');

        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'default_place' => 'Main Church',
            'slots' => [
                [
                    'weekday' => 0,
                    'celebrated_at' => '18:00',
                    'weeks_of_month' => ['2', '4'],
                    'place_source' => 'inherit',
                    'celebrant_source' => 'unset',
                ],
            ],
        ])->assertOk();

        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-30',
        ])->assertOk()
            ->json('data');

        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/apply", [
            'apply_from' => '2026-06-01',
            'fingerprint' => $preview['fingerprint'],
            'until' => '2026-06-30',
        ])->assertOk();

        $this->assertNotNull(
            MassCelebration::query()
                ->where('tenant_id', $tenant->id)
                ->whereDate('celebrated_on', '2026-06-14')
                ->where('generation_status', 'active')
                ->first()
        );
        $this->assertNotNull(
            MassCelebration::query()
                ->where('tenant_id', $tenant->id)
                ->whereDate('celebrated_on', '2026-06-28')
                ->where('generation_status', 'active')
                ->first()
        );
        $this->assertNull(
            MassCelebration::query()
                ->where('tenant_id', $tenant->id)
                ->whereDate('celebrated_on', '2026-06-07')
                ->where('generation_status', 'active')
                ->first()
        );
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function grantPermissions(User $user, array $permissionNames): void
    {
        $ids = Permission::query()->whereIn('name', $permissionNames)->pluck('id');
        $user->permissions()->syncWithoutDetaching($ids);
    }
}
