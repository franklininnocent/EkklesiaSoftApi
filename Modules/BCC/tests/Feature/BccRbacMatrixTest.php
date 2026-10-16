<?php

namespace Modules\BCC\Tests\Feature;

use Modules\BCC\Models\BCC;
use Modules\BCC\Testing\BccCertificationTestCase;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use PHPUnit\Framework\Attributes\Test;

class BccRbacMatrixTest extends BccCertificationTestCase
{
    #[Test]
    public function it_should_deny_unauthenticated_requests(): void
    {
        $this->getJson('/api/bccs')->assertUnauthorized();
        $this->getJson('/api/bccs/dashboard')->assertUnauthorized();
        $this->postJson('/api/bccs', $this->validBccPayload())->assertUnauthorized();
    }

    #[Test]
    public function it_should_allow_view_only_user_to_read_but_not_mutate(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view']);
        $seed = $this->seedBccWithFamilies($ctx['tenant'], 1);
        $bcc = $seed['bcc'];
        $family = $seed['families'][0];

        $this->getJson('/api/bccs')->assertOk();
        $this->getJson("/api/bccs/{$bcc->id}")->assertOk();
        $this->getJson('/api/bccs/dashboard')->assertOk();
        $this->getJson("/api/bccs/{$bcc->id}/members")->assertOk();

        $this->postJson('/api/bccs', $this->validBccPayload())->assertForbidden();
        $this->putJson("/api/bccs/{$bcc->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->deleteJson("/api/bccs/{$bcc->id}")->assertForbidden();
        $this->postJson("/api/bccs/{$bcc->id}/members", ['family_ids' => [$family->id]])->assertForbidden();
        $this->getJson("/api/bccs/{$bcc->id}/leadership/eligible")->assertForbidden();
        $this->postJson("/api/bccs/{$bcc->id}/leadership/assign", [
            'family_member_id' => '00000000-0000-0000-0000-000000000001',
            'role' => 'leader',
        ])->assertForbidden();
    }

    #[Test]
    public function it_should_allow_create_without_edit_but_block_updates(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view', 'bcc.create']);
        $created = $this->postJson('/api/bccs', $this->validBccPayload(['name' => 'Created By Viewer']))
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/bccs/{$created}", ['name' => 'Should Fail'])->assertForbidden();
        $this->deleteJson("/api/bccs/{$created}")->assertForbidden();
    }

    #[Test]
    public function it_should_allow_edit_without_delete(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view', 'bcc.edit']);
        $bcc = BCC::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);

        $this->putJson("/api/bccs/{$bcc->id}", ['name' => 'Renamed BCC'])->assertOk();
        $this->deleteJson("/api/bccs/{$bcc->id}")->assertForbidden();
    }

    #[Test]
    public function it_should_allow_manage_members_without_leadership_mutations(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view', 'bcc.manage_members']);
        $bcc = BCC::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);

        $this->getJson('/api/bccs/families/lookup?search=fam')->assertOk();
        $this->postJson("/api/bccs/{$bcc->id}/members", ['family_ids' => [$family->id]])->assertCreated();

        $member = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'status' => 'active',
        ]);

        $this->getJson("/api/bccs/{$bcc->id}/leadership/eligible")->assertForbidden();
        $this->postJson("/api/bccs/{$bcc->id}/leadership/assign", [
            'family_member_id' => $member->id,
            'role' => 'leader',
        ])->assertForbidden();
    }

    #[Test]
    public function it_should_allow_manage_leadership_without_member_assignments(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view', 'bcc.manage_leadership']);
        $seed = $this->seedBccWithFamilies($ctx['tenant'], 1);
        $bcc = $seed['bcc'];
        $family = $seed['families'][0];
        $member = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'status' => 'active',
        ]);

        $this->getJson("/api/bccs/{$bcc->id}/leadership/eligible")->assertOk();
        $this->postJson("/api/bccs/{$bcc->id}/leadership/assign", [
            'family_member_id' => $member->id,
            'role' => 'coordinator',
            'appointed_date' => now()->toDateString(),
        ])->assertCreated();

        $unassigned = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $this->postJson("/api/bccs/{$bcc->id}/members", ['family_ids' => [$unassigned->id]])->assertForbidden();
        $this->getJson('/api/bccs/families/lookup?search=fam')->assertForbidden();
    }

    #[Test]
    public function it_should_require_delete_permission_to_remove_bcc(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view', 'bcc.edit']);
        $bcc = BCC::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);

        $this->deleteJson("/api/bccs/{$bcc->id}")->assertForbidden();
        $this->assertDatabaseHas('bccs', ['id' => $bcc->id, 'deleted_at' => null]);
    }
}
