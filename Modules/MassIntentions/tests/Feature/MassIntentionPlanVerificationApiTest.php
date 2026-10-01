<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassIntentionAssignment;
use Modules\MassIntentions\Models\MassIntentionAudit;
use Modules\MassIntentions\Models\MassIntentionObligation;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassCelebrationOrigin;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Support\MassObligationStatus;
use Modules\MassIntentions\Support\MassSuppressionReason;
use Modules\MassIntentions\Tests\Support\CreatesMassIntentionTestPayload;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Plan § Tests — remaining acceptance cases for Mass-as-parent model.
 */
class MassIntentionPlanVerificationApiTest extends TestCase
{
    use CreatesMassIntentionTestPayload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function create_rejects_cancelled_and_suppressed_masses(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, [
            'mass.intentions.view',
            'mass.intentions.create',
            'mass.intentions.schedule',
        ]);

        Passport::actingAs($user);

        $cancelledId = $this->createTestCelebration($user);
        MassCelebration::query()->where('id', $cancelledId)->update(['status' => 'cancelled']);

        $suppressedId = $this->createTestCelebration($user, ['celebrated_at' => '07:00']);
        MassCelebration::query()->where('id', $suppressedId)->update([
            'generation_status' => MassGenerationStatus::SUPPRESSED,
            'suppression_reason' => MassSuppressionReason::SCHEDULE_CHANGED,
        ]);

        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $cancelledId,
        ]))->assertStatus(422);

        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $suppressedId,
        ]))->assertStatus(422);
    }

    #[Test]
    public function create_rechecks_eligibility_when_mass_was_cancelled_after_picker(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, [
            'mass.intentions.view',
            'mass.intentions.create',
            'mass.intentions.schedule',
        ]);

        Passport::actingAs($user);

        $massId = $this->createTestCelebration($user);
        MassCelebration::query()->where('id', $massId)->update(['status' => 'cancelled']);

        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $massId,
        ]))->assertStatus(422);
    }

    #[Test]
    public function move_preserves_obligation_id_and_records_move_audit(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, [
            'mass.intentions.view',
            'mass.intentions.create',
            'mass.intentions.schedule',
        ]);

        Passport::actingAs($user);

        $massA = $this->createTestCelebration($user, ['celebrated_at' => '06:00']);
        $massB = $this->createTestCelebration($user, ['celebrated_at' => '08:00']);

        $requestId = $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $massA,
        ]))->json('data.id');

        $obligationId = MassIntentionObligation::query()->where('request_id', $requestId)->value('id');

        $move = $this->postJson("/api/tenant/mass-intentions/requests/{$requestId}/move", [
            'target_celebration_id' => $massB,
        ])->assertOk();

        $this->assertSame($obligationId, MassIntentionObligation::query()->where('request_id', $requestId)->value('id'));
        $this->assertNotEmpty($move->json('meta.audit_ids'));

        $this->assertTrue(
            MassIntentionAudit::query()
                ->where('request_id', $requestId)
                ->where('event_type', 'intention.moved')
                ->exists()
        );
    }

    #[Test]
    public function one_time_mass_date_change_syncs_unsaid_requested_date(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, [
            'mass.intentions.view',
            'mass.intentions.create',
            'mass.intentions.schedule',
        ]);

        Passport::actingAs($user);

        $dayA = Carbon::now()->addWeek()->toDateString();
        $dayB = Carbon::now()->addWeeks(2)->toDateString();
        $massId = $this->createTestCelebration($user, ['celebrated_on' => $dayA]);

        $requestId = $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $massId,
        ]))->json('data.id');

        $this->putJson("/api/tenant/mass-intentions/celebrations/{$massId}", [
            'celebrated_on' => $dayB,
            'celebrated_at' => '06:00',
            'place' => 'Main church',
        ])->assertOk();

        $this->assertSame($dayB, MassIntentionRequest::query()->find($requestId)?->requested_date?->format('Y-m-d'));
    }

    #[Test]
    public function one_time_mass_date_change_blocked_when_intention_marked_said(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, [
            'mass.intentions.view',
            'mass.intentions.create',
            'mass.intentions.schedule',
            'mass.intentions.fulfil',
        ]);

        Passport::actingAs($user);

        $dayA = Carbon::now()->toDateString();
        $dayB = Carbon::now()->addWeek()->toDateString();
        $massId = $this->createTestCelebration($user, ['celebrated_on' => $dayA]);

        $requestId = $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $massId,
        ]))->json('data.id');

        $obligationId = MassIntentionObligation::query()->where('request_id', $requestId)->value('id');

        $this->postJson("/api/tenant/mass-intentions/celebrations/{$massId}/confirm-said", [
            'obligation_ids' => [$obligationId],
        ])->assertOk();

        $this->putJson("/api/tenant/mass-intentions/celebrations/{$massId}", [
            'celebrated_on' => $dayB,
            'celebrated_at' => '06:00',
            'place' => 'Main church',
        ])->assertStatus(422);
    }

    #[Test]
    public function bulk_move_of_ten_with_one_closed_leaves_all_unmoved(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, [
            'mass.intentions.view',
            'mass.intentions.create',
            'mass.intentions.schedule',
            'mass.intentions.review',
        ]);

        Passport::actingAs($user);

        $massA = $this->createTestCelebration($user);
        $massB = $this->createTestCelebration($user, ['celebrated_at' => '08:00']);

        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
                'celebration_id' => $massA,
                'beneficiary_name' => "Bulk {$i}",
            ]))->json('data.id');
        }

        $closedId = $ids[9];
        $this->postJson("/api/tenant/mass-intentions/requests/{$closedId}/close")->assertOk();

        $this->postJson('/api/tenant/mass-intentions/requests/bulk-move', [
            'intention_ids' => $ids,
            'target_celebration_id' => $massB,
        ])->assertStatus(422);

        foreach ($ids as $id) {
            if ($id === $closedId) {
                continue;
            }
            $this->assertSame($massA, $this->getJson("/api/tenant/mass-intentions/requests/{$id}")
                ->json('data.mass_celebration.id'));
        }
    }

    #[Test]
    public function cancel_with_reassignment_writes_user_cancelled_suppression(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, [
            'mass.intentions.view',
            'mass.intentions.create',
            'mass.intentions.schedule',
        ]);

        Passport::actingAs($user);

        $massA = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'origin' => MassCelebrationOrigin::REGULAR,
            'slot_id' => (string) \Illuminate\Support\Str::uuid(),
            'celebrated_on' => Carbon::now()->addWeek(),
            'celebrated_at' => '06:00',
            'place' => 'Main church',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ])->id;

        $massB = $this->createTestCelebration($user, ['celebrated_at' => '08:00']);

        $requestId = $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $massA,
        ]))->json('data.id');

        $obligationId = MassIntentionObligation::query()->where('request_id', $requestId)->value('id');

        $this->postJson("/api/tenant/mass-intentions/celebrations/{$massA}/cancel", [
            'reason' => 'Weather',
            'reassignments' => [
                ['obligation_id' => $obligationId, 'celebration_id' => $massB],
            ],
        ])->assertOk();

        $massAFresh = MassCelebration::query()->find($massA);
        $this->assertSame('cancelled', $massAFresh?->status);
        $this->assertSame(MassSuppressionReason::USER_CANCELLED, $massAFresh?->suppression_reason);

        $this->assertSame($massB, $this->getJson("/api/tenant/mass-intentions/requests/{$requestId}")
            ->json('data.mass_celebration.id'));
    }

    #[Test]
    public function dashboard_needs_a_tick_requires_active_assignment_and_needs_a_mass_counts_legacy_only(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create', 'mass.intentions.schedule']);

        Passport::actingAs($user);

        MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::OPEN,
            'beneficiary_name' => 'Legacy only',
            'beneficiary_place' => 'Place',
            'mass_intention_category_id' => $this->massIntentionCategoryIdFor($user),
            'intention_text' => 'Thanksgiving',
            'requested_date' => Carbon::now()->subDays(5),
            'mass_count_requested' => 1,
            'created_by_user_id' => $user->id,
            'announce_name' => true,
        ]);

        $pastDay = Carbon::now()->subDay()->toDateString();
        $pastMass = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'origin' => MassCelebrationOrigin::ONE_TIME,
            'celebrated_on' => $pastDay,
            'celebrated_at' => '06:00',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        $tickRequest = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::OPEN,
            'beneficiary_name' => 'Needs tick',
            'intention_text' => 'Test',
            'mass_count_requested' => 1,
            'created_by_user_id' => $user->id,
        ]);

        $obligation = MassIntentionObligation::query()->create([
            'tenant_id' => $tenant->id,
            'request_id' => $tickRequest->id,
            'sequence' => 1,
            'status' => MassObligationStatus::SCHEDULED,
        ]);

        MassIntentionAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'obligation_id' => $obligation->id,
            'celebration_id' => $pastMass->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $user->id,
        ]);

        $home = $this->getJson('/api/tenant/mass-intentions/home')->json('data.operational');

        $this->assertSame(1, $home['needs_a_mass']);
        $this->assertGreaterThanOrEqual(1, $home['needs_a_tick']);
    }

    #[Test]
    public function cancel_leaves_assignments_when_reassignment_missing(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, [
            'mass.intentions.view',
            'mass.intentions.create',
            'mass.intentions.schedule',
        ]);

        Passport::actingAs($user);

        $massId = $this->createTestCelebration($user);
        $requestId = $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $massId,
        ]))->json('data.id');

        $this->postJson("/api/tenant/mass-intentions/celebrations/{$massId}/cancel", [
            'reason' => 'No target',
            'reassignments' => [],
        ])->assertStatus(422);

        $this->assertTrue(
            DB::table('mass_intention_assignments as a')
                ->join('mass_intention_obligations as o', 'o.id', '=', 'a.obligation_id')
                ->where('o.request_id', $requestId)
                ->whereNull('a.unassigned_at')
                ->exists()
        );
    }

    private function grantPermissions(User $user, array $permissionNames): void
    {
        $ids = Permission::query()->whereIn('name', $permissionNames)->pluck('id');
        $user->permissions()->syncWithoutDetaching($ids);
    }
}
