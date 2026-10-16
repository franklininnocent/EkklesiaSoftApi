<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassCelebrationOrigin;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Traceability for plan §15 acceptance criteria A1 and A9.
 */
class MassSchedulePlanAcceptanceApiTest extends TestCase
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
    public function a1_scenario_a_each_weekday_slot_materializes_once_in_apply_window(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $scheduleId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.schedule.id');
        $slots = [
            ['weekday' => 0, 'celebrated_at' => '09:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
            ['weekday' => 3, 'celebrated_at' => '18:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
            ['weekday' => 6, 'celebrated_at' => '17:30', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
        ];
        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", ['slots' => $slots])->assertOk();

        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-14',
        ])->assertOk()->json('data');

        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/apply", [
            'apply_from' => '2026-06-01',
            'fingerprint' => $preview['fingerprint'],
            'until' => '2026-06-14',
        ])->assertOk();

        $firstSunday = '2026-06-07';
        $firstWednesday = '2026-06-03';
        $firstSaturday = '2026-06-06';

        foreach ([$firstSunday => 0, $firstWednesday => 3, $firstSaturday => 6] as $date => $weekday) {
            $count = MassCelebration::query()
                ->where('tenant_id', $tenant->id)
                ->where('origin', MassCelebrationOrigin::REGULAR)
                ->whereDate('celebrated_on', $date)
                ->whereNotNull('slot_id')
                ->count();
            $this->assertSame(1, $count, "Expected one generated Mass on {$date} (weekday {$weekday})");
        }

        $slotIds = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->where('origin', MassCelebrationOrigin::REGULAR)
            ->whereBetween('celebrated_on', ['2026-06-01', '2026-06-14'])
            ->whereNotNull('slot_id')
            ->distinct()
            ->pluck('slot_id');

        $this->assertCount(3, $slotIds);
        $this->assertGreaterThanOrEqual(
            3,
            MassCelebration::query()
                ->where('tenant_id', $tenant->id)
                ->where('origin', MassCelebrationOrigin::REGULAR)
                ->whereBetween('celebrated_on', ['2026-06-01', '2026-06-14'])
                ->whereNotNull('slot_id')
                ->count()
        );
    }

    #[Test]
    public function a9_one_time_create_without_place_or_priest_returns_201(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $this->postJson('/api/tenant/mass-intentions/celebrations', [
            'celebrated_on' => '2026-06-10',
            'celebrated_at' => '11:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.origin', MassCelebrationOrigin::ONE_TIME);

        $row = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('celebrated_on', '2026-06-10')
            ->firstOrFail();

        $this->assertNull($row->place);
        $this->assertNull($row->celebrant_name);
    }

    #[Test]
    public function a8_view_only_user_cannot_create_one_time_mass(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);
        Passport::actingAs($user);

        $this->postJson('/api/tenant/mass-intentions/celebrations', [
            'celebrated_on' => '2026-06-10',
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
