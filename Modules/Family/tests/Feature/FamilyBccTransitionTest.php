<?php

namespace Modules\Family\Tests\Feature;

use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BccFamilyMembership;
use Modules\Donations\Models\DonationPayment;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\FamilyMemberHistory;
use Modules\Family\Models\HouseholdTransition;
use Modules\Family\Testing\FamilyCertificationTestCase;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;

class FamilyBccTransitionTest extends FamilyCertificationTestCase
{
    protected User $user;

    protected Tenant $tenant;

    #[Test]
    public function it_relocates_family_and_creates_bcc_membership_history(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->user = $ctx['user'];
        $this->tenant = $ctx['tenant'];
        $this->grantTransitionPermissions();

        $sourceBcc = BCC::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);
        $targetBcc = BCC::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $household = $this->seedHousehold($this->tenant);
        $family = $household['family'];
        $this->assignFamilyToBcc($family, $sourceBcc, '2024-01-01');

        $transitionId = (string) Str::uuid();
        $effectiveDate = now()->toDateString();

        $response = $this->postJson("/api/families/{$family->id}/relocate-bcc", [
            'target_bcc_id' => $targetBcc->id,
            'effective_date' => $effectiveDate,
            'transition_id' => $transitionId,
            'historical_note' => 'Parish boundary change',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.to_bcc_id', $targetBcc->id)
            ->assertJsonPath('data.from_bcc_id', $sourceBcc->id)
            ->assertJsonPath('data.transfer_mode', 'RELOCATION');

        $family->refresh();
        $this->assertSame($targetBcc->id, $family->bcc_id);

        $this->assertDatabaseHas('bcc_family_memberships', [
            'family_id' => $family->id,
            'bcc_id' => $sourceBcc->id,
            'status' => BccFamilyMembership::STATUS_EXITED,
            'is_current' => false,
            'transition_id' => $transitionId,
        ]);
        $this->assertDatabaseHas('bcc_family_memberships', [
            'family_id' => $family->id,
            'bcc_id' => $targetBcc->id,
            'status' => BccFamilyMembership::STATUS_ACTIVE,
            'is_current' => true,
            'transition_id' => $transitionId,
        ]);

        $this->assertTrue(
            FamilyMemberHistory::query()
                ->where('from_family_id', $family->id)
                ->where('to_family_id', $family->id)
                ->where('transition_id', $transitionId)
                ->where('transition_type', 'RELOCATION')
                ->exists()
        );

        $this->assertDatabaseHas('household_transitions', [
            'tenant_id' => $this->tenant->id,
            'transition_id' => $transitionId,
            'type' => HouseholdTransition::TYPE_RELOCATION,
            'status' => HouseholdTransition::STATUS_COMPLETED,
        ]);
    }

    #[Test]
    public function it_rejects_same_bcc_relocation(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->user = $ctx['user'];
        $this->tenant = $ctx['tenant'];
        $this->grantTransitionPermissions();

        $bcc = BCC::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);
        $household = $this->seedHousehold($this->tenant);
        $family = $household['family'];
        $this->assignFamilyToBcc($family, $bcc);

        $response = $this->postJson("/api/families/{$family->id}/relocate-bcc", [
            'target_bcc_id' => $bcc->id,
            'effective_date' => now()->toDateString(),
            'transition_id' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'TARGET_BCC_ALREADY_ASSIGNED');
    }

    #[Test]
    public function it_assigns_bcc_when_family_has_no_current_bcc(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->user = $ctx['user'];
        $this->tenant = $ctx['tenant'];
        $this->grantTransitionPermissions();

        $targetBcc = BCC::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);
        $household = $this->seedHousehold($this->tenant);
        $family = $household['family'];
        $this->assertNull($family->bcc_id);

        $response = $this->postJson("/api/families/{$family->id}/relocate-bcc", [
            'target_bcc_id' => $targetBcc->id,
            'effective_date' => now()->toDateString(),
            'transition_id' => (string) Str::uuid(),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.transfer_mode', 'ASSIGN')
            ->assertJsonPath('data.to_bcc_id', $targetBcc->id)
            ->assertJsonPath('data.from_bcc_id', null);

        $this->assertSame($targetBcc->id, $family->fresh()->bcc_id);
    }

    #[Test]
    public function it_rejects_migrated_family_relocation(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->user = $ctx['user'];
        $this->tenant = $ctx['tenant'];
        $this->grantTransitionPermissions();

        $targetBcc = BCC::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'migrated',
        ]);

        $response = $this->postJson("/api/families/{$family->id}/relocate-bcc", [
            'target_bcc_id' => $targetBcc->id,
            'effective_date' => now()->toDateString(),
            'transition_id' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'FAMILY_ALREADY_MIGRATED');
    }

    #[Test]
    public function it_rejects_future_effective_date(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->user = $ctx['user'];
        $this->tenant = $ctx['tenant'];
        $this->grantTransitionPermissions();

        $targetBcc = BCC::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);
        $household = $this->seedHousehold($this->tenant);
        $family = $household['family'];

        $response = $this->postJson("/api/families/{$family->id}/relocate-bcc", [
            'target_bcc_id' => $targetBcc->id,
            'effective_date' => now()->addDay()->toDateString(),
            'transition_id' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422);
        $this->assertTrue(
            $response->json('code') === 'INVALID_EFFECTIVE_DATE'
            || $response->json('errors.effective_date') !== null
        );
    }

    #[Test]
    public function it_establishes_new_household_via_marriage(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->user = $ctx['user'];
        $this->tenant = $ctx['tenant'];
        $this->grantTransitionPermissions();

        $targetBcc = BCC::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $brideHousehold = $this->seedHousehold($this->tenant, ['family_name' => 'Lopez Family']);
        $groomHousehold = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Singh Family',
            'status' => 'active',
        ]);
        $groom = FamilyMember::factory()->head()->active()->create([
            'family_id' => $groomHousehold->id,
            'first_name' => 'Raj',
            'last_name' => 'Singh',
            'gender' => 'male',
        ]);

        $bride = $brideHousehold['spouse'];
        $brideOriginHead = $brideHousehold['head'];
        $transitionId = (string) Str::uuid();

        $response = $this->postJson('/api/families/marriage-transition', [
            'transition_id' => $transitionId,
            'outcome' => 'new_household',
            'effective_date' => now()->toDateString(),
            'bride_member_id' => $bride->id,
            'groom_member_id' => $groom->id,
            'new_household' => [
                'family_name' => 'Lopez-Singh Family',
                'bcc_id' => $targetBcc->id,
                'address_line_1' => '200 Wedding Lane',
                'city' => 'Springfield',
            ],
            'new_household_head_member_id' => (string) $groom->id,
            'origin_successions' => [[
                'origin_family_id' => $brideHousehold['family']->id,
                'replacement_head_member_id' => $brideOriginHead->id,
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.outcome', 'new_household');

        $newFamilyId = $response->json('data.new_family_id');
        $this->assertNotEmpty($newFamilyId);

        $this->assertDatabaseHas('families', [
            'id' => $newFamilyId,
            'family_name' => 'Lopez-Singh Family',
            'bcc_id' => $targetBcc->id,
            'status' => 'active',
        ]);

        $this->assertSame($newFamilyId, $groom->fresh()->family_id);
        $this->assertSame($newFamilyId, $bride->fresh()->family_id);
        $this->assertSame('self', $groom->fresh()->relationship_to_head);
        $this->assertSame('spouse', $bride->fresh()->relationship_to_head);

        $this->assertDatabaseHas('household_transitions', [
            'transition_id' => $transitionId,
            'type' => HouseholdTransition::TYPE_MARRIAGE,
            'status' => HouseholdTransition::STATUS_COMPLETED,
        ]);
    }

    #[Test]
    public function it_auto_succession_when_one_member_remains(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->user = $ctx['user'];
        $this->tenant = $ctx['tenant'];
        $this->grantTransitionPermissions();

        $targetBcc = BCC::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $originFamily = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Reyes Family',
            'status' => 'active',
        ]);
        $departing = FamilyMember::factory()->head()->active()->create([
            'family_id' => $originFamily->id,
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'gender' => 'female',
        ]);
        $remaining = FamilyMember::factory()->child()->active()->create([
            'family_id' => $originFamily->id,
            'first_name' => 'Luis',
            'last_name' => 'Reyes',
            'gender' => 'male',
        ]);

        $partnerFamily = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $partner = FamilyMember::factory()->head()->active()->create([
            'family_id' => $partnerFamily->id,
            'first_name' => 'Carlos',
            'last_name' => 'Diaz',
            'gender' => 'male',
        ]);

        $this->postJson('/api/families/marriage-transition', [
            'transition_id' => (string) Str::uuid(),
            'outcome' => 'new_household',
            'effective_date' => now()->toDateString(),
            'bride_member_id' => $departing->id,
            'groom_member_id' => $partner->id,
            'new_household' => [
                'family_name' => 'Reyes-Diaz Family',
                'bcc_id' => $targetBcc->id,
            ],
            'new_household_head_member_id' => (string) $partner->id,
        ])->assertOk();

        $remaining->refresh();
        $this->assertSame('self', $remaining->relationship_to_head);
        $this->assertSame('active', $originFamily->fresh()->status);

        $this->assertDatabaseHas('family_member_histories', [
            'member_id' => $remaining->id,
            'from_family_id' => $originFamily->id,
            'to_family_id' => $originFamily->id,
            'transition_type' => 'SUCCESSION',
        ]);
    }

    #[Test]
    public function it_requires_explicit_succession_when_multiple_remain(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->user = $ctx['user'];
        $this->tenant = $ctx['tenant'];
        $this->grantTransitionPermissions();

        $targetBcc = BCC::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $originFamily = $this->seedHousehold($this->tenant, ['family_name' => 'Nguyen Family']);
        $departing = $originFamily['spouse'];
        $remainingHead = $originFamily['head'];
        $remainingChild = $originFamily['child'];

        $partnerFamily = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $partner = FamilyMember::factory()->head()->active()->create([
            'family_id' => $partnerFamily->id,
            'gender' => 'male',
        ]);

        $payload = [
            'transition_id' => (string) Str::uuid(),
            'outcome' => 'new_household',
            'effective_date' => now()->toDateString(),
            'bride_member_id' => $departing->id,
            'groom_member_id' => $partner->id,
            'new_household' => [
                'family_name' => 'Nguyen Partner Family',
                'bcc_id' => $targetBcc->id,
            ],
            'new_household_head_member_id' => (string) $partner->id,
        ];

        $this->postJson('/api/families/marriage-transition', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'HEAD_SUCCESSION_REQUIRED');

        $payload['origin_successions'] = [[
            'origin_family_id' => $originFamily['family']->id,
            'replacement_head_member_id' => $remainingHead->id,
        ]];

        $this->postJson('/api/families/marriage-transition', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('self', $remainingHead->fresh()->relationship_to_head);
        $this->assertSame('daughter', $remainingChild->fresh()->relationship_to_head);
    }

    #[Test]
    public function it_joins_existing_household_via_marriage(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->user = $ctx['user'];
        $this->tenant = $ctx['tenant'];
        $this->grantTransitionPermissions();

        $originFamily = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $joining = FamilyMember::factory()->head()->active()->create([
            'family_id' => $originFamily->id,
            'first_name' => 'Elena',
            'last_name' => 'Park',
            'gender' => 'female',
        ]);

        $targetHousehold = $this->seedHousehold($this->tenant, ['family_name' => 'Kim Family']);
        $partner = $targetHousehold['head'];
        $transitionId = (string) Str::uuid();

        $response = $this->postJson('/api/families/marriage-transition', [
            'transition_id' => $transitionId,
            'outcome' => 'join_existing',
            'effective_date' => now()->toDateString(),
            'bride_member_id' => $joining->id,
            'groom_member_id' => $partner->id,
            'target_family_id' => $targetHousehold['family']->id,
            'joining_member_id' => $joining->id,
            'partner_member_id' => $partner->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.outcome', 'join_existing')
            ->assertJsonPath('data.target_family_id', $targetHousehold['family']->id);

        $joining->refresh();
        $this->assertSame($targetHousehold['family']->id, $joining->family_id);
        $this->assertSame('spouse', $joining->relationship_to_head);

        $this->assertDatabaseHas('family_member_histories', [
            'member_id' => $joining->id,
            'from_family_id' => $originFamily->id,
            'to_family_id' => $targetHousehold['family']->id,
            'transition_type' => 'JOIN',
            'transition_id' => $transitionId,
        ]);
    }

    #[Test]
    public function it_rejects_same_family_marriage(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->user = $ctx['user'];
        $this->tenant = $ctx['tenant'];
        $this->grantTransitionPermissions();

        $targetBcc = BCC::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);
        $household = $this->seedHousehold($this->tenant);

        $response = $this->postJson('/api/families/marriage-transition', [
            'transition_id' => (string) Str::uuid(),
            'outcome' => 'new_household',
            'effective_date' => now()->toDateString(),
            'bride_member_id' => $household['head']->id,
            'groom_member_id' => $household['spouse']->id,
            'new_household' => [
                'family_name' => 'Invalid Family',
                'bcc_id' => $targetBcc->id,
            ],
            'new_household_head_member_id' => (string) $household['head']->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'SAME_FAMILY_MARRIAGE_NOT_ALLOWED');
    }

    #[Test]
    public function it_preserves_donation_history_on_source_family(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->user = $ctx['user'];
        $this->tenant = $ctx['tenant'];
        $this->grantTransitionPermissions();

        $targetBcc = BCC::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $originFamily = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $departing = FamilyMember::factory()->head()->active()->create([
            'family_id' => $originFamily->id,
            'gender' => 'female',
        ]);

        $partnerFamily = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $partner = FamilyMember::factory()->head()->active()->create([
            'family_id' => $partnerFamily->id,
            'gender' => 'male',
        ]);

        $payment = DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $originFamily->id,
            'payment_number' => 'PAY-TRANS-001',
            'payer_name' => 'Legacy Payer',
            'payment_date' => now()->toDateString(),
            'amount' => 1500.00,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $this->postJson('/api/families/marriage-transition', [
            'transition_id' => (string) Str::uuid(),
            'outcome' => 'new_household',
            'effective_date' => now()->toDateString(),
            'bride_member_id' => $departing->id,
            'groom_member_id' => $partner->id,
            'new_household' => [
                'family_name' => 'Merged Household',
                'bcc_id' => $targetBcc->id,
            ],
            'new_household_head_member_id' => (string) $partner->id,
        ])->assertOk();

        $payment->refresh();
        $this->assertSame($originFamily->id, $payment->family_id);
        $this->assertSame('1500.00', number_format((float) $payment->amount, 2, '.', ''));
        $this->assertSame('succeeded', $payment->status);
    }

    #[Test]
    public function it_is_idempotent_on_duplicate_transition_id(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->user = $ctx['user'];
        $this->tenant = $ctx['tenant'];
        $this->grantTransitionPermissions();

        $sourceBcc = BCC::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);
        $targetBcc = BCC::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);
        $household = $this->seedHousehold($this->tenant);
        $family = $household['family'];
        $this->assignFamilyToBcc($family, $sourceBcc, '2024-01-01');

        $transitionId = (string) Str::uuid();
        $payload = [
            'target_bcc_id' => $targetBcc->id,
            'effective_date' => now()->toDateString(),
            'transition_id' => $transitionId,
        ];

        $first = $this->postJson("/api/families/{$family->id}/relocate-bcc", $payload);
        $first->assertOk()->assertJsonPath('success', true);

        $membershipCount = BccFamilyMembership::query()
            ->where('family_id', $family->id)
            ->count();

        $second = $this->postJson("/api/families/{$family->id}/relocate-bcc", $payload);
        $second->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.code', 'TRANSITION_ALREADY_COMPLETED');

        $this->assertSame(
            $membershipCount,
            BccFamilyMembership::query()->where('family_id', $family->id)->count()
        );
        $this->assertSame(1, HouseholdTransition::query()->where('transition_id', $transitionId)->count());
    }

    #[Test]
    public function it_rejects_cross_tenant_bcc(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->user = $ctx['user'];
        $this->tenant = $ctx['tenant'];
        $this->grantTransitionPermissions();

        $otherTenant = Tenant::factory()->active()->create();
        $foreignBcc = BCC::factory()->active()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        $household = $this->seedHousehold($this->tenant);
        $family = $household['family'];

        $preview = $this->getJson(
            "/api/families/{$family->id}/relocate-bcc/preview?target_bcc_id={$foreignBcc->id}"
        );
        $preview->assertStatus(404)
            ->assertJsonPath('code', 'TARGET_BCC_NOT_FOUND');

        $relocate = $this->postJson("/api/families/{$family->id}/relocate-bcc", [
            'target_bcc_id' => $foreignBcc->id,
            'effective_date' => now()->toDateString(),
            'transition_id' => (string) Str::uuid(),
        ]);
        $relocate->assertStatus(422)
            ->assertJsonValidationErrors(['target_bcc_id']);
    }

    private function grantTransitionPermissions(): void
    {
        $ids = [];
        foreach ([
            'families.bcc.relocate',
            'families.marriage.transition',
            'families.history.view',
            'families.history.correct',
        ] as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'module' => 'Family',
                    'category' => 'family',
                    'scope' => Permission::SCOPE_TENANT,
                    'active' => 1,
                    'tenant_id' => null,
                    'is_custom' => false,
                ]
            );
            $ids[] = $permission->id;
        }

        $this->user->permissions()->syncWithoutDetaching($ids);
        $this->user->clearPermissionsCache();
    }

    private function assignFamilyToBcc(Family $family, BCC $bcc, string $joinedDate = '2024-01-01'): void
    {
        BccFamilyMembership::create([
            'tenant_id' => $family->tenant_id,
            'bcc_id' => $bcc->id,
            'family_id' => $family->id,
            'status' => BccFamilyMembership::STATUS_ACTIVE,
            'joined_date' => $joinedDate,
            'is_current' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $family->update(['bcc_id' => $bcc->id]);
    }
}
