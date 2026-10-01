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

class MassScheduleTemporaryApiTest extends TestCase
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
    public function friday_only_temporary_replaces_friday_regular_mass_only(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $regularId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.schedule.id');
        $this->applySchedule($regularId, [
            ['weekday' => 4, 'celebrated_at' => '09:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
            ['weekday' => 5, 'celebrated_at' => '09:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
        ], '2026-06-01', '2026-06-14');

        $temp = $this->postJson('/api/tenant/mass-intentions/schedules/temporaries', [
            'name' => 'Summer Friday evening',
            'coverage_mode' => 'selected_weekdays',
            'selected_weekdays' => [5],
        ])->assertCreated()->json('data.schedule.id');

        $this->putJson("/api/tenant/mass-intentions/schedules/{$temp}/draft", [
            'slots' => [
                ['weekday' => 5, 'celebrated_at' => '18:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
            ],
        ])->assertOk();

        $this->applySchedule($temp, [
            ['weekday' => 5, 'celebrated_at' => '18:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
        ], '2026-06-01', '2026-06-14', '2026-06-14');

        $thursday = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('celebrated_on', '2026-06-04')
            ->first();
        $this->assertNotNull($thursday);
        $this->assertSame(MassCelebrationOrigin::REGULAR, $thursday->origin);
        $this->assertSame('09:00', substr((string) $thursday->celebrated_at, 0, 5));

        $friday = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('celebrated_on', '2026-06-05')
            ->where('generation_status', 'active')
            ->first();
        $this->assertNotNull($friday);
        $this->assertSame(MassCelebrationOrigin::TEMPORARY, $friday->origin);
        $this->assertSame('18:00', substr((string) $friday->celebrated_at, 0, 5));
    }

    #[Test]
    public function overlapping_temporary_schedules_are_rejected(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $first = $this->postJson('/api/tenant/mass-intentions/schedules/temporaries', [
            'name' => 'First',
            'coverage_mode' => 'full_week',
        ])->assertCreated()->json('data.schedule.id');

        $this->putJson("/api/tenant/mass-intentions/schedules/{$first}/draft", [
            'slots' => [['weekday' => 1, 'celebrated_at' => '08:00', 'place_source' => 'unset', 'celebrant_source' => 'unset']],
        ])->assertOk();
        $this->applySchedule($first, [], '2026-06-01', '2026-06-10', '2026-06-10');

        $second = $this->postJson('/api/tenant/mass-intentions/schedules/temporaries', [
            'name' => 'Second',
            'coverage_mode' => 'full_week',
        ])->assertCreated()->json('data.schedule.id');

        $this->putJson("/api/tenant/mass-intentions/schedules/{$second}/draft", [
            'slots' => [['weekday' => 1, 'celebrated_at' => '09:00', 'place_source' => 'unset', 'celebrant_source' => 'unset']],
        ])->assertOk();

        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$second}/preview", [
            'apply_from' => '2026-06-05',
            'effective_to' => '2026-06-12',
        ])->assertOk()->json('data');

        $this->postJson("/api/tenant/mass-intentions/schedules/{$second}/apply", [
            'apply_from' => '2026-06-05',
            'effective_to' => '2026-06-12',
            'fingerprint' => $preview['fingerprint'],
        ])->assertStatus(422);
    }

    /**
     * @param  list<array<string, mixed>>  $slots
     */
    private function applySchedule(
        string $scheduleId,
        array $slots,
        string $applyFrom,
        string $until,
        ?string $effectiveTo = null
    ): void {
        if ($slots !== []) {
            $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", ['slots' => $slots])->assertOk();
        }

        $body = [
            'apply_from' => $applyFrom,
            'until' => $until,
        ];
        if ($effectiveTo !== null) {
            $body['effective_to'] = $effectiveTo;
        }

        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", $body)
            ->assertOk()
            ->json('data');

        $applyBody = [
            'apply_from' => $applyFrom,
            'fingerprint' => $preview['fingerprint'],
            'until' => $until,
        ];
        if ($effectiveTo !== null) {
            $applyBody['effective_to'] = $effectiveTo;
        }

        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/apply", $applyBody)->assertOk();
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
