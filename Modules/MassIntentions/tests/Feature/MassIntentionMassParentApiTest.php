<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassCelebrationOrigin;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Tests\Support\CreatesMassIntentionTestPayload;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassIntentionMassParentApiTest extends TestCase
{
    use CreatesMassIntentionTestPayload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function create_requires_celebration_id(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create']);

        Passport::actingAs($user);

        $this->postJson('/api/tenant/mass-intentions/requests', [
            'beneficiary_name' => 'Test',
            'beneficiary_place' => 'Place',
            'mass_intention_category_id' => $this->massIntentionCategoryIdFor($user),
            'mass_count' => 1,
        ])->assertStatus(422);
    }

    #[Test]
    public function create_with_mass_assigns_obligation_and_syncs_date(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create', 'mass.intentions.schedule']);

        Passport::actingAs($user);

        $day = Carbon::now()->addWeek()->toDateString();
        $celebrationId = $this->createTestCelebration($user, ['celebrated_on' => $day]);

        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $celebrationId,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.requested_date', $day)
            ->assertJsonPath('data.mass_celebration.id', $celebrationId)
            ->assertJsonPath('data.needs_a_mass', false);
    }

    #[Test]
    public function move_updates_mass_and_requested_date(): void
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
        $massA = $this->createTestCelebration($user, ['celebrated_on' => $dayA, 'celebrated_at' => '06:00']);
        $massB = $this->createTestCelebration($user, ['celebrated_on' => $dayB, 'celebrated_at' => '09:00']);

        $requestId = $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $massA,
        ]))->json('data.id');

        $this->postJson("/api/tenant/mass-intentions/requests/{$requestId}/move", [
            'target_celebration_id' => $massB,
        ])
            ->assertOk()
            ->assertJsonPath('data.mass_celebration.id', $massB)
            ->assertJsonPath('data.requested_date', $dayB);
    }

    #[Test]
    public function cancel_without_reassignment_target_fails_when_unsaid_intentions_exist(): void
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
        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $massId,
        ]))->assertCreated();

        $this->postJson("/api/tenant/mass-intentions/celebrations/{$massId}/cancel", [
            'reason' => 'Priest unavailable',
            'reassignments' => [],
        ])->assertStatus(422);

        $this->assertSame('scheduled', MassCelebration::query()->find($massId)?->status);
    }

    #[Test]
    public function needs_a_mass_list_filter_matches_home_operational_count(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create', 'mass.intentions.schedule']);

        Passport::actingAs($user);

        MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::OPEN,
            'beneficiary_name' => 'Needs Mass',
            'beneficiary_place' => 'Place',
            'mass_intention_category_id' => $this->massIntentionCategoryIdFor($user),
            'intention_text' => 'Thanksgiving',
            'requested_date' => Carbon::now()->addWeek(),
            'mass_count_requested' => 1,
            'created_by_user_id' => $user->id,
            'announce_name' => true,
        ]);

        $celebrationId = $this->createTestCelebration($user);
        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $celebrationId,
            'beneficiary_name' => 'Has Mass',
        ]))->assertCreated();

        $homeNeeds = $this->getJson('/api/tenant/mass-intentions/home')->json('data.operational.needs_a_mass');
        $listNeeds = $this->getJson('/api/tenant/mass-intentions/requests?needs_a_mass=1')->json('total');
        $queueNeeds = $this->getJson('/api/tenant/mass-intentions/requests?queue=needs_a_mass')->json('total');

        $this->assertSame(1, $homeNeeds);
        $this->assertSame(1, $listNeeds);
        $this->assertSame(1, $queueNeeds);
    }

    #[Test]
    public function create_rejects_mass_count_greater_than_one(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, [
            'mass.intentions.view',
            'mass.intentions.create',
            'mass.intentions.schedule',
        ]);

        Passport::actingAs($user);

        $celebrationId = $this->createTestCelebration($user);

        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $celebrationId,
            'mass_count' => 2,
        ]))->assertStatus(422);
    }

    #[Test]
    public function show_includes_assignment_history(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create', 'mass.intentions.schedule']);

        Passport::actingAs($user);

        $massA = $this->createTestCelebration($user, ['celebrated_at' => '06:00']);
        $massB = $this->createTestCelebration($user, ['celebrated_at' => '09:00']);

        $requestId = $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $massA,
        ]))->json('data.id');

        $this->postJson("/api/tenant/mass-intentions/requests/{$requestId}/move", [
            'target_celebration_id' => $massB,
        ])->assertOk();

        $show = $this->getJson("/api/tenant/mass-intentions/requests/{$requestId}")
            ->assertOk()
            ->json('meta.history');

        $this->assertCount(2, $show['assignments']);
        $this->assertNotEmpty($show['audits']);
    }

    #[Test]
    public function bulk_move_is_all_or_nothing_when_one_intention_is_closed(): void
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

        $openId = $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $massA,
            'beneficiary_name' => 'Open Row',
        ]))->json('data.id');

        $closedId = $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $massA,
            'beneficiary_name' => 'Closed Row',
        ]))->json('data.id');

        $this->postJson("/api/tenant/mass-intentions/requests/{$closedId}/close")->assertOk();

        $this->postJson('/api/tenant/mass-intentions/requests/bulk-move', [
            'intention_ids' => [$openId, $closedId],
            'target_celebration_id' => $massB,
        ])->assertStatus(422);

        $this->assertSame($massA, $this->getJson("/api/tenant/mass-intentions/requests/{$openId}")
            ->json('data.mass_celebration.id'));
    }

    #[Test]
    public function close_when_due_uses_mass_date_and_legacy_unassigned_stays_open(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create', 'mass.intentions.schedule']);

        Passport::actingAs($user);

        $pastDay = Carbon::now()->subDays(3)->toDateString();
        $pastMass = $this->createTestCelebration($user, ['celebrated_on' => $pastDay]);

        $pastRequestId = $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $pastMass,
            'beneficiary_name' => 'Past Mass',
        ]))->json('data.id');

        $legacyId = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::OPEN,
            'beneficiary_name' => 'Legacy unassigned',
            'beneficiary_place' => 'Place',
            'mass_intention_category_id' => $this->massIntentionCategoryIdFor($user),
            'intention_text' => 'Thanksgiving',
            'requested_date' => Carbon::now()->subDays(10),
            'mass_count_requested' => 1,
            'created_by_user_id' => $user->id,
            'announce_name' => true,
        ])->id;

        $this->getJson('/api/tenant/mass-intentions/requests/'.$pastRequestId)->assertOk();
        $this->getJson('/api/tenant/mass-intentions/requests/'.$legacyId)->assertOk();

        $this->assertSame(MassIntentionStatus::CLOSED, MassIntentionRequest::query()->find($pastRequestId)?->status);
        $this->assertSame(MassIntentionStatus::OPEN, MassIntentionRequest::query()->find($legacyId)?->status);
    }

    #[Test]
    public function create_rejects_another_tenants_celebration(): void
    {
        $tenantA = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $tenantB = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'active' => 1]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'active' => 1]);
        $this->grantPermissions($userA, ['mass.intentions.view', 'mass.intentions.create', 'mass.intentions.schedule']);
        $this->grantPermissions($userB, ['mass.intentions.view', 'mass.intentions.create', 'mass.intentions.schedule']);

        Passport::actingAs($userB);
        $foreignMass = $this->createTestCelebration($userB);

        Passport::actingAs($userA);
        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($userA, [
            'celebration_id' => $foreignMass,
        ]))->assertStatus(422);
    }

    #[Test]
    public function mark_schedule_changed_requires_no_active_intentions(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, [
            'mass.intentions.view',
            'mass.intentions.create',
            'mass.intentions.schedule',
        ]);

        Passport::actingAs($user);

        $massId = MassCelebration::query()->create([
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

        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'celebration_id' => $massId,
        ]))->assertCreated();

        $this->postJson("/api/tenant/mass-intentions/celebrations/{$massId}/mark-schedule-changed")
            ->assertStatus(422);

        $emptySlotMass = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'origin' => MassCelebrationOrigin::REGULAR,
            'slot_id' => (string) \Illuminate\Support\Str::uuid(),
            'celebrated_on' => Carbon::now()->addWeeks(2),
            'celebrated_at' => '07:00',
            'place' => 'Main church',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ])->id;

        $this->postJson("/api/tenant/mass-intentions/celebrations/{$emptySlotMass}/mark-schedule-changed")
            ->assertOk()
            ->assertJsonPath('data.generation_status', MassGenerationStatus::SUPPRESSED);
    }

    #[Test]
    public function legacy_open_intention_without_mass_requires_celebration_on_update(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create']);

        Passport::actingAs($user);

        $legacy = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::OPEN,
            'beneficiary_name' => 'Legacy',
            'beneficiary_place' => 'Place',
            'mass_intention_category_id' => $this->massIntentionCategoryIdFor($user),
            'intention_text' => 'Thanksgiving',
            'requested_date' => Carbon::now()->addWeek(),
            'mass_count_requested' => 1,
            'created_by_user_id' => $user->id,
            'announce_name' => true,
        ]);

        $this->putJson("/api/tenant/mass-intentions/requests/{$legacy->id}", [
            'beneficiary_name' => 'Legacy',
            'beneficiary_place' => 'Place',
            'mass_intention_category_id' => $this->massIntentionCategoryIdFor($user),
            'mass_count' => 1,
        ])->assertStatus(422);

        $massId = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'origin' => MassCelebrationOrigin::ONE_TIME,
            'celebrated_on' => Carbon::now()->addWeek(),
            'celebrated_at' => '06:00',
            'place' => 'Main church',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ])->id;

        $this->putJson("/api/tenant/mass-intentions/requests/{$legacy->id}", [
            'beneficiary_name' => 'Legacy',
            'beneficiary_place' => 'Place',
            'mass_intention_category_id' => $this->massIntentionCategoryIdFor($user),
            'mass_count' => 1,
            'celebration_id' => $massId,
        ])
            ->assertOk()
            ->assertJsonPath('data.needs_a_mass', false);
    }

    private function grantPermissions(User $user, array $permissionNames): void
    {
        $ids = Permission::query()->whereIn('name', $permissionNames)->pluck('id');
        $user->permissions()->syncWithoutDetaching($ids);
    }
}
