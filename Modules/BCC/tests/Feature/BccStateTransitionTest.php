<?php

namespace Modules\BCC\Tests\Feature;

use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BccFamilyMembership;
use Modules\BCC\Testing\BccCertificationTestCase;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use PHPUnit\Framework\Attributes\Test;

class BccStateTransitionTest extends BccCertificationTestCase
{
    #[Test]
    public function it_should_close_previous_membership_interval_on_transfer(): void
    {
        $ctx = $this->actingAsTenantWith([
            'bcc.view', 'bcc.manage_members', 'bcc.manage_leadership',
        ]);
        $source = BCC::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);
        $target = BCC::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);

        $this->postJson("/api/bccs/{$source->id}/members", ['family_ids' => [$family->id]])->assertCreated();
        $originalMembershipId = BccFamilyMembership::query()
            ->where('bcc_id', $source->id)
            ->where('family_id', $family->id)
            ->where('is_current', true)
            ->value('id');

        $this->postJson("/api/bccs/{$target->id}/members", [
            'family_ids' => [$family->id],
            'transfer' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('bcc_family_memberships', [
            'id' => $originalMembershipId,
            'is_current' => false,
        ]);
        $this->assertDatabaseHas('bcc_family_memberships', [
            'bcc_id' => $target->id,
            'family_id' => $family->id,
            'is_current' => true,
        ]);
        $this->assertEquals($target->id, $family->fresh()->bcc_id);
    }

    #[Test]
    public function it_should_reject_duplicate_current_membership(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view', 'bcc.manage_members']);
        $bcc = BCC::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);

        $this->postJson("/api/bccs/{$bcc->id}/members", ['family_ids' => [$family->id]])->assertCreated();
        $this->postJson("/api/bccs/{$bcc->id}/members", ['family_ids' => [$family->id]])->assertStatus(422);

        $this->assertEquals(
            1,
            BccFamilyMembership::query()
                ->where('bcc_id', $bcc->id)
                ->where('family_id', $family->id)
                ->where('is_current', true)
                ->count()
        );
    }

    #[Test]
    public function it_should_persist_exit_reason_when_leadership_is_terminated(): void
    {
        $ctx = $this->actingAsTenantWith([
            'bcc.view', 'bcc.manage_members', 'bcc.manage_leadership',
        ]);
        $seed = $this->seedBccWithFamilies($ctx['tenant'], 1);
        $bcc = $seed['bcc'];
        $family = $seed['families'][0];
        $member = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'status' => 'active',
        ]);

        $leaderId = $this->postJson("/api/bccs/{$bcc->id}/leadership/assign", [
            'family_member_id' => $member->id,
            'role' => 'leader',
            'appointment_date' => now()->subDays(30)->toDateString(),
            'effective_from' => now()->subDays(30)->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/bccs/{$bcc->id}/leadership/{$leaderId}/terminate", [
            'effective_to' => now()->toDateString(),
            'exit_reason' => 'resigned',
            'remarks' => 'Stepped down.',
        ])->assertOk()
            ->assertJsonPath('data.exit_reason', 'resigned')
            ->assertJsonPath('data.status', 'vacated');

        $this->assertDatabaseHas('bcc_leaders', [
            'id' => $leaderId,
            'exit_reason' => 'resigned',
            'status' => 'vacated',
            'is_active' => false,
        ]);
    }

    #[Test]
    public function it_should_unassign_families_when_bcc_is_soft_deleted(): void
    {
        $ctx = $this->actingAsTenantWith([
            'bcc.view', 'bcc.delete', 'bcc.manage_members',
        ]);
        $seed = $this->seedBccWithFamilies($ctx['tenant'], 2);
        $bcc = $seed['bcc'];
        $familyIds = collect($seed['families'])->pluck('id')->all();

        foreach ($familyIds as $familyId) {
            $this->assertDatabaseHas('families', ['id' => $familyId, 'bcc_id' => $bcc->id]);
        }

        $this->deleteJson("/api/bccs/{$bcc->id}")->assertOk();

        foreach ($familyIds as $familyId) {
            $this->assertNull(Family::find($familyId)->bcc_id);
            $this->assertDatabaseMissing('bcc_family_memberships', [
                'bcc_id' => $bcc->id,
                'family_id' => $familyId,
                'is_current' => true,
            ]);
        }

        $this->assertSoftDeleted('bccs', ['id' => $bcc->id]);
    }

    #[Test]
    public function it_should_complete_handover_by_closing_outgoing_and_activating_incoming(): void
    {
        $ctx = $this->actingAsTenantWith([
            'bcc.view', 'bcc.manage_members', 'bcc.manage_leadership',
        ]);
        $seed = $this->seedBccWithFamilies($ctx['tenant'], 1);
        $bcc = $seed['bcc'];
        $family = $seed['families'][0];
        $outgoingMember = FamilyMember::factory()->create(['family_id' => $family->id, 'status' => 'active']);
        $incomingMember = FamilyMember::factory()->create(['family_id' => $family->id, 'status' => 'active']);

        $outgoingId = $this->postJson("/api/bccs/{$bcc->id}/leadership/assign", [
            'family_member_id' => $outgoingMember->id,
            'role' => 'leader',
            'appointment_date' => now()->subDays(60)->toDateString(),
            'effective_from' => now()->subDays(60)->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/bccs/{$bcc->id}/leadership/handover", [
            'outgoing_leader_id' => $outgoingId,
            'incoming_family_member_id' => $incomingMember->id,
            'outgoing_effective_to' => now()->toDateString(),
            'outgoing_exit_reason' => 'resigned',
            'appointment_date' => now()->toDateString(),
            'effective_from' => now()->toDateString(),
        ])->assertOk();

        $this->assertDatabaseHas('bcc_leaders', [
            'id' => $outgoingId,
            'is_active' => false,
            'exit_reason' => 'resigned',
        ]);

        $current = $this->getJson("/api/bccs/{$bcc->id}/leadership/current");
        $current->assertOk();
        $current->assertJsonPath('data.active_count', 1);
        $current->assertJsonPath('data.leaders.0.family_member_id', $incomingMember->id);
    }
}
