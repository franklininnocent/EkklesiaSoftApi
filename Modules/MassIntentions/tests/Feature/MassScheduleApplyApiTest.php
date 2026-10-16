<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Services\MassOccurrenceReconciler;
use Modules\MassIntentions\Support\MassCelebrationOrigin;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassScheduleApplyApiTest extends TestCase
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
    public function apply_regular_schedule_materializes_sunday_mass(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);

        Passport::actingAs($user);

        $bundle = $this->getJson('/api/tenant/mass-intentions/schedules/regular')
            ->assertOk()
            ->json('data');
        $scheduleId = $bundle['schedule']['id'];

        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'default_place' => 'Main Church',
            'slots' => [
                [
                    'weekday' => 0,
                    'celebrated_at' => '09:00',
                    'place_source' => 'inherit',
                    'celebrant_source' => 'unset',
                ],
            ],
        ])->assertOk();

        $applyFrom = '2026-06-01';
        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", [
            'apply_from' => $applyFrom,
            'until' => '2026-06-14',
        ])->assertOk()
            ->json('data');

        $this->assertGreaterThan(0, $preview['counts']['create']);
        $fingerprint = $preview['fingerprint'];

        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/apply", [
            'apply_from' => $applyFrom,
            'fingerprint' => $fingerprint,
            'until' => '2026-06-14',
        ])->assertOk();

        $sunday = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('celebrated_on', '2026-06-07')
            ->where('origin', MassCelebrationOrigin::REGULAR)
            ->first();

        $this->assertNotNull($sunday);
        $this->assertSame('09:00', substr((string) $sunday->celebrated_at, 0, 5));
        $this->assertSame('Main Church', $sunday->place);
        $this->assertNotNull($sunday->slot_id);
    }

    #[Test]
    public function reconciling_twice_does_not_duplicate_occurrences(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);

        Passport::actingAs($user);

        $scheduleId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')
            ->assertOk()
            ->json('data.schedule.id');

        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'slots' => [
                [
                    'weekday' => 0,
                    'celebrated_at' => '09:00',
                    'place_source' => 'inherit',
                    'celebrant_source' => 'unset',
                ],
            ],
        ])->assertOk();

        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-14',
        ])->assertOk()
            ->json('data');

        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/apply", [
            'apply_from' => '2026-06-01',
            'fingerprint' => $preview['fingerprint'],
            'until' => '2026-06-14',
        ])->assertOk();

        $countAfterApply = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('slot_id')
            ->whereDate('celebrated_on', '>=', '2026-06-01')
            ->whereDate('celebrated_on', '<=', '2026-06-14')
            ->count();

        $reconciler = app(MassOccurrenceReconciler::class);
        $from = Carbon::parse('2026-06-01')->startOfDay();
        $to = Carbon::parse('2026-06-14')->startOfDay();
        $secondPass = $reconciler->materialize($tenant->id, $from, $to);

        $this->assertSame(0, $secondPass['create']);
        $countAfterSecond = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('slot_id')
            ->whereDate('celebrated_on', '>=', '2026-06-01')
            ->whereDate('celebrated_on', '<=', '2026-06-14')
            ->count();

        $this->assertSame($countAfterApply, $countAfterSecond);
        $this->assertGreaterThan(0, $countAfterApply);
    }

    #[Test]
    public function stale_fingerprint_returns_409(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);

        Passport::actingAs($user);

        $scheduleId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')
            ->assertOk()
            ->json('data.schedule.id');

        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'slots' => [
                ['weekday' => 1, 'celebrated_at' => '08:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
            ],
        ])->assertOk();

        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/apply", [
            'apply_from' => '2026-06-01',
            'fingerprint' => str_repeat('a', 64),
        ])->assertStatus(409);
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
