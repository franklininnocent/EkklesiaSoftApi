<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassCelebrationKeepOnScheduleApiTest extends TestCase
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
    public function keep_on_schedule_sets_exception_and_blocks_slot_removal(): void
    {
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
            'apply_from' => '2026-06-01',
            'until' => '2026-06-14',
        ])->assertOk()->json('data');
        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/apply", [
            'apply_from' => '2026-06-01',
            'fingerprint' => $preview['fingerprint'],
            'until' => '2026-06-14',
        ])->assertOk();

        $celebration = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('celebrated_on', '2026-06-07')
            ->firstOrFail();

        $this->postJson("/api/tenant/mass-intentions/celebrations/{$celebration->id}/keep-on-schedule")
            ->assertOk();

        $celebration->refresh();
        $this->assertTrue($celebration->is_exception);

        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", ['slots' => []])->assertOk();
        $removePreview = $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-14',
        ])->assertOk()->json('data');

        $celebration->refresh();
        $this->assertTrue($celebration->is_exception);
        $this->assertSame(MassGenerationStatus::ACTIVE, $celebration->generation_status);
        $this->assertGreaterThan(0, $removePreview['counts']['suppress'] + ($removePreview['counts']['skip'] ?? 0));
    }

    #[Test]
    public function view_only_user_cannot_keep_on_schedule(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $scheduler = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($viewer, ['mass.intentions.view']);
        $this->grantPermissions($scheduler, ['mass.intentions.view', 'mass.intentions.schedule']);

        Passport::actingAs($scheduler);
        $scheduleId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.schedule.id');
        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'slots' => [['weekday' => 0, 'celebrated_at' => '09:00', 'place_source' => 'unset', 'celebrant_source' => 'unset']],
        ])->assertOk();
        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-14',
        ])->assertOk()->json('data');
        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/apply", [
            'apply_from' => '2026-06-01',
            'fingerprint' => $preview['fingerprint'],
            'until' => '2026-06-14',
        ])->assertOk();

        $celebration = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('celebrated_on', '2026-06-07')
            ->firstOrFail();

        Passport::actingAs($viewer);
        $this->postJson("/api/tenant/mass-intentions/celebrations/{$celebration->id}/keep-on-schedule")
            ->assertForbidden();
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
