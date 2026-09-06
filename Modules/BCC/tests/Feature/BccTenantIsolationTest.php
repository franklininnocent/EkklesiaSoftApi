<?php

namespace Modules\BCC\Tests\Feature;

use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BccFamilyMembership;
use Modules\BCC\Services\BccFamilyMembershipService;
use Modules\BCC\Testing\BccCertificationTestCase;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;

class BccTenantIsolationTest extends BccCertificationTestCase
{
    #[Test]
    public function it_should_return_404_when_tenant_a_reads_tenant_b_bcc(): void
    {
        $tenantA = $this->actingAsTenantWith([
            'bcc.view', 'bcc.edit', 'bcc.delete', 'bcc.manage_members', 'bcc.manage_leadership',
        ]);
        $tenantB = Tenant::factory()->create();
        $bccB = BCC::factory()->active()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Foreign BCC',
        ]);
        $snapshot = $this->snapshotBcc($bccB);

        $this->getJson("/api/bccs/{$bccB->id}")->assertNotFound();
        $this->getJson("/api/bccs/{$bccB->id}/dashboard")->assertNotFound();

        $this->assertDatabaseHas('bccs', $snapshot);
    }

    #[Test]
    public function it_should_return_404_when_tenant_a_updates_tenant_b_bcc(): void
    {
        $this->actingAsTenantWith(['bcc.view', 'bcc.edit']);
        $tenantB = Tenant::factory()->create();
        $bccB = BCC::factory()->active()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Original Name',
        ]);

        $this->putJson("/api/bccs/{$bccB->id}", ['name' => 'Hacked Name'])->assertNotFound();

        $this->assertDatabaseHas('bccs', [
            'id' => $bccB->id,
            'name' => 'Original Name',
        ]);
    }

    #[Test]
    public function it_should_return_404_when_tenant_a_deletes_tenant_b_bcc(): void
    {
        $this->actingAsTenantWith(['bcc.view', 'bcc.delete']);
        $tenantB = Tenant::factory()->create();
        $bccB = BCC::factory()->active()->create(['tenant_id' => $tenantB->id]);

        $this->deleteJson("/api/bccs/{$bccB->id}")->assertNotFound();

        $this->assertDatabaseHas('bccs', [
            'id' => $bccB->id,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function it_should_exclude_other_tenant_bccs_from_list_and_dashboard(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view']);
        BCC::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);
        $other = Tenant::factory()->create();
        BCC::factory()->count(3)->active()->create(['tenant_id' => $other->id]);

        $list = $this->getJson('/api/bccs');
        $list->assertOk();
        $this->assertEquals(1, $list->json('total'));

        $dashboard = $this->getJson('/api/bccs/dashboard');
        $dashboard->assertOk();
        $this->assertEquals(1, $dashboard->json('data.bccs.total'));
        $this->assertEquals(1, $dashboard->json('data.snapshot.bccs_total'));
    }

    #[Test]
    public function it_should_not_assign_tenant_b_family_to_tenant_a_bcc(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view', 'bcc.manage_members']);
        $bcc = BCC::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);
        $tenantB = Tenant::factory()->create();
        $foreignFamily = Family::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);

        $this->postJson("/api/bccs/{$bcc->id}/members", [
            'family_ids' => [$foreignFamily->id],
        ])->assertNotFound();

        $this->assertDatabaseMissing('bcc_family_memberships', [
            'bcc_id' => $bcc->id,
            'family_id' => $foreignFamily->id,
            'is_current' => true,
        ]);
        $this->assertNull($foreignFamily->fresh()->bcc_id);
    }

    #[Test]
    public function it_should_leave_membership_unchanged_after_cross_tenant_remove_attempt(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view', 'bcc.manage_members']);
        $tenantB = Tenant::factory()->create();
        $bccB = BCC::factory()->active()->create(['tenant_id' => $tenantB->id]);
        $familyB = Family::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);

        $service = app(BccFamilyMembershipService::class);
        $service->assignFamilies((int) $tenantB->id, $bccB->id, [$familyB->id]);
        $membership = BccFamilyMembership::query()
            ->where('bcc_id', $bccB->id)
            ->where('family_id', $familyB->id)
            ->where('is_current', true)
            ->firstOrFail();
        $snapshot = $this->snapshotMembership($membership);

        $this->actingAsTenantWith(['bcc.view', 'bcc.manage_members']);
        $this->deleteJson("/api/bccs/{$bccB->id}/members/{$membership->id}")->assertNotFound();

        $this->assertDatabaseHas('bcc_family_memberships', $snapshot);
    }
}
