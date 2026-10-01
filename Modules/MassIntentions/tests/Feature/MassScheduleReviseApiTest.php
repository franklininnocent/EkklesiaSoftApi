<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\MassIntentions\Support\MassSuppressionReason;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassScheduleReviseApiTest extends TestCase
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
    public function revising_time_keeps_same_celebration_id_and_slot_id(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $scheduleId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.schedule.id');
        $this->applySlots($scheduleId, [
            ['weekday' => 0, 'celebrated_at' => '09:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
        ]);

        $first = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('celebrated_on', '2026-06-07')
            ->firstOrFail();
        $slotId = (string) $first->slot_id;
        $celebrationId = $first->id;

        $published = $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.published.slots.0');
        $this->assertSame($slotId, $published['slot_id']);

        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'slots' => [
                [
                    'slot_id' => $slotId,
                    'weekday' => 0,
                    'celebrated_at' => '10:00',
                    'place_source' => 'unset',
                    'celebrant_source' => 'unset',
                ],
            ],
        ])->assertOk();

        $this->applyFromPreview($scheduleId, '2026-06-01');

        $updated = MassCelebration::query()->findOrFail($celebrationId);
        $this->assertSame('10:00', substr((string) $updated->celebrated_at, 0, 5));
        $this->assertSame($slotId, $updated->slot_id);
        $this->assertSame(1, MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->where('slot_id', $slotId)
            ->whereDate('celebrated_on', '2026-06-07')
            ->count());
    }

    #[Test]
    public function removing_slot_suppresses_future_mass_and_hides_from_operational_list(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $scheduleId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.schedule.id');
        $this->applySlots($scheduleId, [
            ['weekday' => 0, 'celebrated_at' => '09:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
        ]);

        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'slots' => [],
        ])->assertOk();

        $this->applyFromPreview($scheduleId, '2026-06-01');

        $row = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('celebrated_on', '2026-06-07')
            ->firstOrFail();

        $this->assertSame(MassGenerationStatus::SUPPRESSED, $row->generation_status);
        $this->assertSame(MassSuppressionReason::SCHEDULE_CHANGED, $row->suppression_reason);
        $this->assertSame('scheduled', $row->status);

        $this->getJson('/api/tenant/mass-intentions/celebrations?from=2026-06-07&to=2026-06-07')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * @param  list<array<string, mixed>>  $slots
     */
    private function applySlots(string $scheduleId, array $slots): void
    {
        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", ['slots' => $slots])->assertOk();
        $this->applyFromPreview($scheduleId, '2026-06-01');
    }

    private function applyFromPreview(string $scheduleId, string $applyFrom): void
    {
        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", [
            'apply_from' => $applyFrom,
            'until' => '2026-06-14',
        ])->assertOk()->json('data');

        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/apply", [
            'apply_from' => $applyFrom,
            'fingerprint' => $preview['fingerprint'],
            'until' => '2026-06-14',
        ])->assertOk();
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
