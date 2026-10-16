<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassCelebrationOrigin;
use Modules\MassIntentions\Support\MassScheduleConstants;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassCelebrationOnDemandMaterializeApiTest extends TestCase
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
    public function listing_a_future_week_materializes_beyond_apply_horizon(): void
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

        $this->assertNull(
            MassCelebration::query()
                ->where('tenant_id', $tenant->id)
                ->whereDate('celebrated_on', '2026-12-20')
                ->first()
        );

        $this->getJson('/api/tenant/mass-intentions/celebrations?from=2026-12-20&to=2026-12-26&per_page=100')
            ->assertOk()
            ->assertJsonPath('data.0.celebrated_on', '2026-12-20')
            ->assertJsonPath('data.0.origin', MassCelebrationOrigin::REGULAR);

        $countAfterFirst = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('celebrated_on', '2026-12-20')
            ->count();

        $this->getJson('/api/tenant/mass-intentions/celebrations?from=2026-12-20&to=2026-12-26&per_page=100')
            ->assertOk();

        $this->assertSame(
            $countAfterFirst,
            MassCelebration::query()
                ->where('tenant_id', $tenant->id)
                ->whereDate('celebrated_on', '2026-12-20')
                ->count()
        );
    }

    #[Test]
    public function celebrations_index_materializes_inside_outer_database_transaction(): void
    {
        Carbon::setTestNow('2026-09-29 10:00:00');

        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $scheduleId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.schedule.id');
        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'slots' => [
                ['weekday' => 0, 'celebrated_at' => '09:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
            ],
        ])->assertOk();

        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", [
            'apply_from' => '2026-09-29',
            'until' => '2026-10-03',
        ])->json('data');

        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/apply", [
            'apply_from' => '2026-09-29',
            'fingerprint' => $preview['fingerprint'],
            'until' => '2026-10-03',
        ])->assertOk();

        DB::transaction(function (): void {
            $this->getJson(
                '/api/tenant/mass-intentions/celebrations?from=2026-09-27&to=2026-10-03&per_page=100&page=1&include_cancelled=1'
            )->assertOk();
            $this->getJson(
                '/api/tenant/mass-intentions/celebrations?from=2026-09-27&to=2026-10-03&per_page=100&page=1&include_cancelled=1'
            )->assertOk();
        });
    }

    #[Test]
    public function list_range_beyond_generation_cap_returns_422(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        Passport::actingAs($user);

        $beyond = Carbon::parse('2026-06-01')
            ->addDays(MassScheduleConstants::MAX_APPLY_RANGE_DAYS + 1);
        $from = $beyond->copy()->subDays(6)->toDateString();
        $to = $beyond->toDateString();

        $this->getJson("/api/tenant/mass-intentions/celebrations?from={$from}&to={$to}")
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
