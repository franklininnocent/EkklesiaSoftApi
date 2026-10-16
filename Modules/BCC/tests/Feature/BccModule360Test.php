<?php

namespace Modules\BCC\Tests\Feature;

use Modules\BCC\Models\BCC;
use Modules\BCC\Services\BccFamilyMembershipService;
use Modules\BCC\Testing\BccCertificationTestCase;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use PHPUnit\Framework\Attributes\Test;

class BccModule360Test extends BccCertificationTestCase
{
    #[Test]
    public function it_should_write_audit_log_when_bcc_is_created(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view', 'bcc.create']);

        $bccId = $this->postJson('/api/bccs', $this->validBccPayload(['name' => 'Audited BCC']))
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('bcc_audit_logs', [
            'tenant_id' => $ctx['tenant']->id,
            'bcc_id' => $bccId,
            'target_type' => 'bcc',
            'target_id' => $bccId,
            'event' => 'bcc.created',
        ]);
    }

    #[Test]
    public function it_should_sync_family_pointer_when_membership_service_opens_interval(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view']);
        $bcc = BCC::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);
        $family = Family::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'bcc_id' => null,
            'status' => 'active',
        ]);

        $family->bcc_id = $bcc->id;
        $family->save();

        app(BccFamilyMembershipService::class)
            ->syncFromFamilyPointer($family, null, (int) $ctx['tenant']->id);

        $this->assertEquals($bcc->id, $family->fresh()->bcc_id);
        $this->assertDatabaseHas('bcc_family_memberships', [
            'bcc_id' => $bcc->id,
            'family_id' => $family->id,
            'is_current' => true,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function it_should_surface_unlinked_families_attention_on_parish_dashboard(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view']);
        $bcc = BCC::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);
        Family::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'bcc_id' => $bcc->id,
            'status' => 'active',
        ]);
        Family::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'bcc_id' => null,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/bccs/dashboard');
        $response->assertOk();

        $attentionTypes = collect($response->json('data.attention'))->pluck('type')->all();
        $this->assertContains('unlinked_families', $attentionTypes);
        $this->assertEquals(1, $response->json('data.snapshot.families_without_bcc'));
        $this->assertEquals(1, $response->json('data.coverage.unlinked'));
    }

    #[Test]
    public function it_should_match_people_unknown_gender_count_with_overview_drilldown(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view', 'bcc.manage_members']);
        $bcc = BCC::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);
        $family = Family::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'status' => 'active',
        ]);
        $this->postJson("/api/bccs/{$bcc->id}/members", ['family_ids' => [$family->id]])->assertCreated();

        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'gender' => 'male',
            'status' => 'active',
            'date_of_birth' => now()->subYears(40)->toDateString(),
        ]);
        FamilyMember::factory()->count(2)->create([
            'family_id' => $family->id,
            'gender' => null,
            'status' => 'active',
            'date_of_birth' => null,
        ]);

        $overview = $this->getJson("/api/bccs/{$bcc->id}/dashboard");
        $overview->assertOk();

        $drilldown = $this->getJson("/api/bccs/{$bcc->id}/people?gender=unknown");
        $drilldown->assertOk();

        $this->assertEquals(
            $overview->json('data.gender.unknown.count'),
            $drilldown->json('meta.total')
        );
    }

    #[Test]
    public function it_should_write_membership_audit_events_on_assign_and_remove(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view', 'bcc.manage_members']);
        $bcc = BCC::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);

        $this->postJson("/api/bccs/{$bcc->id}/members", ['family_ids' => [$family->id]])->assertCreated();
        $membershipId = $this->getJson("/api/bccs/{$bcc->id}/members")
            ->json('data.0.id');
        $this->deleteJson("/api/bccs/{$bcc->id}/members/{$membershipId}")->assertOk();

        $events = $this->getJson("/api/bccs/{$bcc->id}/audit-logs")
            ->json('data');
        $eventNames = collect($events)->pluck('event')->all();
        $this->assertContains('membership.assigned', $eventNames);
        $this->assertContains('membership.removed', $eventNames);
    }
}
