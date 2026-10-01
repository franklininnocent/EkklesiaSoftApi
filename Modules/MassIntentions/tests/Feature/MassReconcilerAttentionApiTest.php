<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassIntentionAssignment;
use Modules\MassIntentions\Models\MassIntentionObligation;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Support\MassObligationStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Plan § Tests — reconciler schedule attention skips past/cancelled/exception/said-only rows.
 */
class MassReconcilerAttentionApiTest extends TestCase
{
    private string $scheduleId = '';

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
    public function preview_skips_past_mass_with_intentions_when_removing_slot(): void
    {
        [$user, $celebration] = $this->materializeSundayMass();
        $this->attachIntention($user, $celebration);

        Carbon::setTestNow('2026-06-10 10:00:00');

        $conflicts = $this->previewRemoveAllSlots($user);

        $this->assertNotContainsCelebration($conflicts, $celebration->id);
    }

    #[Test]
    public function preview_skips_cancelled_mass_with_intentions_when_removing_slot(): void
    {
        [$user, $celebration] = $this->materializeSundayMass();
        $this->attachIntention($user, $celebration);
        $celebration->update(['status' => 'cancelled']);

        $conflicts = $this->previewRemoveAllSlots($user);

        $this->assertNotContainsCelebration($conflicts, $celebration->id);
    }

    #[Test]
    public function preview_skips_exception_mass_with_intentions_when_removing_slot(): void
    {
        [$user, $celebration] = $this->materializeSundayMass();
        $this->attachIntention($user, $celebration);
        $celebration->update(['is_exception' => true]);

        $conflicts = $this->previewRemoveAllSlots($user);

        $this->assertNotContainsCelebration($conflicts, $celebration->id);
    }

    #[Test]
    public function preview_skips_said_only_mass_when_removing_slot(): void
    {
        [$user, $celebration] = $this->materializeSundayMass();
        $this->grantPermissions($user, ['mass.intentions.fulfil']);
        $obligationId = $this->attachIntention($user, $celebration);

        $this->postJson("/api/tenant/mass-intentions/celebrations/{$celebration->id}/confirm-said", [
            'obligation_ids' => [$obligationId],
        ])->assertOk();

        $conflicts = $this->previewRemoveAllSlots($user);

        $this->assertNotContainsCelebration($conflicts, $celebration->id);
    }

    #[Test]
    public function preview_time_change_on_said_mass_with_unsaid_offers_move_unsaid_only(): void
    {
        [$user, $celebration] = $this->materializeSundayMass();
        $this->grantPermissions($user, ['mass.intentions.fulfil']);
        $saidObligation = $this->attachIntention($user, $celebration, 'Said person');
        $this->attachIntention($user, $celebration, 'Unsaid person');

        $this->postJson("/api/tenant/mass-intentions/celebrations/{$celebration->id}/confirm-said", [
            'obligation_ids' => [$saidObligation],
        ])->assertOk();

        $slotId = (string) $celebration->slot_id;
        $this->putJson("/api/tenant/mass-intentions/schedules/{$this->scheduleId}/draft", [
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

        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$this->scheduleId}/preview", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-14',
        ])->assertOk()->json('data');

        $match = collect($preview['conflicts'] ?? [])->firstWhere('celebration_id', $celebration->id);
        $this->assertNotNull($match);
        $this->assertSame('move_unsaid_only', $match['reason_code']);
        $this->assertGreaterThanOrEqual(1, $match['intention_count']);
    }

    #[Test]
    public function preview_still_reports_conflict_for_future_unsaid_when_removing_slot(): void
    {
        [$user, $celebration] = $this->materializeSundayMass();
        $this->attachIntention($user, $celebration);

        $conflicts = $this->previewRemoveAllSlots($user);

        $this->assertContainsCelebration($conflicts, $celebration->id);
        $match = collect($conflicts)->firstWhere('celebration_id', $celebration->id);
        $this->assertContains($match['reason_code'], ['suppress_blocked', 'move_then_remove']);
    }

    /**
     * @return array{0: User, 1: MassCelebration}
     */
    private function materializeSundayMass(): array
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $this->scheduleId = (string) $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.schedule.id');

        $this->putJson("/api/tenant/mass-intentions/schedules/{$this->scheduleId}/draft", [
            'slots' => [
                ['weekday' => 0, 'celebrated_at' => '09:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
            ],
        ])->assertOk();

        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$this->scheduleId}/preview", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-14',
        ])->assertOk()->json('data');

        $this->postJson("/api/tenant/mass-intentions/schedules/{$this->scheduleId}/apply", [
            'apply_from' => '2026-06-01',
            'fingerprint' => $preview['fingerprint'],
            'until' => '2026-06-14',
        ])->assertOk();

        $celebration = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('celebrated_on', '2026-06-07')
            ->firstOrFail();

        return [$user, $celebration];
    }

    private function attachIntention(User $user, MassCelebration $celebration, string $name = 'Family Name'): string
    {
        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $celebration->tenant_id,
            'status' => MassIntentionStatus::OPEN,
            'beneficiary_name' => $name,
            'intention_text' => 'Repose',
            'mass_count_requested' => 1,
            'created_by_user_id' => $user->id,
        ]);

        $obligation = MassIntentionObligation::query()->create([
            'tenant_id' => $celebration->tenant_id,
            'request_id' => $request->id,
            'sequence' => 1,
            'status' => MassObligationStatus::SCHEDULED,
        ]);

        MassIntentionAssignment::query()->create([
            'tenant_id' => $celebration->tenant_id,
            'obligation_id' => $obligation->id,
            'celebration_id' => $celebration->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $user->id,
        ]);

        return (string) $obligation->id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function previewRemoveAllSlots(User $user): array
    {
        Passport::actingAs($user);

        $this->putJson("/api/tenant/mass-intentions/schedules/{$this->scheduleId}/draft", [
            'slots' => [],
        ])->assertOk();

        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$this->scheduleId}/preview", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-14',
        ])->assertOk()->json('data');

        return $preview['conflicts'] ?? [];
    }

    /**
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function assertNotContainsCelebration(array $conflicts, string $celebrationId): void
    {
        $ids = collect($conflicts)->pluck('celebration_id')->all();
        $this->assertNotContains($celebrationId, $ids, 'Expected no schedule attention conflict for this Mass.');
    }

    /**
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function assertContainsCelebration(array $conflicts, string $celebrationId): void
    {
        $ids = collect($conflicts)->pluck('celebration_id')->all();
        $this->assertContains($celebrationId, $ids);
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
