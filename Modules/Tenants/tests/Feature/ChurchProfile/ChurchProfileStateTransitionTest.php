<?php

namespace Modules\Tenants\Tests\Feature\ChurchProfile;

use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Support\LeadershipAssignmentStatus;
use Modules\Tenants\Testing\ChurchProfileCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class ChurchProfileStateTransitionTest extends ChurchProfileCertificationTestCase
{
    #[Test]
    public function it_should_reject_leadership_assign_when_profile_missing(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $this->seedLeadershipRoles();
        $person = $this->makePerson($ctx['tenant']);
        $deaconRole = LeadershipRole::query()->where('title', 'Deacon')->firstOrFail();

        $this->postJson('/api/church-profile/leadership/assign', [
            'is_external' => false,
            'person_id' => $person->id,
            'role_id' => $deaconRole->id,
            'start_date' => '2026-01-01',
        ])->assertNotFound();
    }

    #[Test]
    public function it_should_allow_assign_after_profile_auto_create(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $this->seedLeadershipRoles();
        $person = $this->makePerson($ctx['tenant']);
        $deaconRole = LeadershipRole::query()->where('title', 'Deacon')->firstOrFail();

        $this->getJson('/api/church-profile')->assertOk();

        $this->postJson('/api/church-profile/leadership/assign', [
            'is_external' => false,
            'person_id' => $person->id,
            'role_id' => $deaconRole->id,
            'start_date' => '2026-01-01',
        ])->assertCreated();
    }

    #[Test]
    public function it_should_create_update_and_delete_statistics(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.create', 'church.settings.edit', 'church.settings.delete']);

        $created = $this->postJson('/api/church-statistics', [
            'year' => 2026,
            'month' => 3,
            'membership_count' => 80,
            'weekly_attendance' => 60,
        ])->assertCreated();
        $statId = $created->json('data.id');

        $this->putJson("/api/church-statistics/{$statId}", [
            'membership_count' => 85,
        ])->assertOk()->assertJsonPath('data.membership_count', 85);

        $this->deleteJson("/api/church-statistics/{$statId}")->assertOk();
        $this->assertDatabaseMissing('church_statistics', ['id' => $statId]);
    }

    #[Test]
    public function it_should_reject_duplicate_statistics_for_same_period(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.create']);
        $this->seedStatistic($ctx['tenant'], ['year' => 2026, 'month' => 4]);

        $this->postJson('/api/church-statistics', [
            'year' => 2026,
            'month' => 4,
            'membership_count' => 10,
        ])->assertStatus(422);
    }

    #[Test]
    public function it_should_create_update_and_delete_social_media(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.create', 'church.settings.edit', 'church.settings.delete']);

        $created = $this->postJson('/api/church-social-media', [
            'platform' => 'youtube',
            'url' => 'https://youtube.com/@parish',
            'username' => 'parishtube',
        ])->assertCreated();
        $socialId = $created->json('data.id');

        $this->putJson("/api/church-social-media/{$socialId}", [
            'username' => 'parishmedia',
        ])->assertOk()->assertJsonPath('data.username', 'parishmedia');

        $this->deleteJson("/api/church-social-media/{$socialId}")->assertOk();
        $this->assertDatabaseMissing('church_social_media', ['id' => $socialId]);
    }

    #[Test]
    public function it_should_reject_terminate_on_completed_assignment(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $this->seedLeadershipRoles();
        $profile = $this->seedChurchProfile($ctx['tenant']);
        $person = $this->makePerson($ctx['tenant']);
        $deaconRole = LeadershipRole::query()->where('title', 'Deacon')->firstOrFail();

        $assignment = LeadershipAssignment::factory()->completed()->create([
            'tenant_id' => $ctx['tenant']->id,
            'church_profile_id' => $profile->id,
            'person_id' => $person->id,
            'role_id' => $deaconRole->id,
        ]);

        $this->putJson("/api/church-profile/leadership/assignments/{$assignment->id}/terminate", [
            'end_date' => now()->toDateString(),
            'exit_reason_code' => 'completed',
        ])->assertStatus(422);
    }

    #[Test]
    public function it_should_reject_update_on_completed_assignment(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $this->seedLeadershipRoles();
        $profile = $this->seedChurchProfile($ctx['tenant']);
        $person = $this->makePerson($ctx['tenant']);
        $deaconRole = LeadershipRole::query()->where('title', 'Deacon')->firstOrFail();

        $assignment = LeadershipAssignment::factory()->completed()->create([
            'tenant_id' => $ctx['tenant']->id,
            'church_profile_id' => $profile->id,
            'person_id' => $person->id,
            'role_id' => $deaconRole->id,
        ]);

        $this->putJson("/api/church-profile/leadership/assignments/{$assignment->id}", [
            'first_name' => 'Changed',
            'last_name' => 'Name',
            'role_id' => $deaconRole->id,
            'start_date' => '2020-01-01',
        ])->assertStatus(422);
    }

    #[Test]
    public function it_should_terminate_active_assignment_and_write_audit_log(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $this->seedLeadershipRoles();
        $profile = $this->seedChurchProfile($ctx['tenant']);
        $person = $this->makePerson($ctx['tenant']);
        $deaconRole = LeadershipRole::query()->where('title', 'Deacon')->firstOrFail();

        $assignment = LeadershipAssignment::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'church_profile_id' => $profile->id,
            'person_id' => $person->id,
            'role_id' => $deaconRole->id,
            'status' => LeadershipAssignmentStatus::ACTIVE,
        ]);

        $this->putJson("/api/church-profile/leadership/assignments/{$assignment->id}/terminate", [
            'end_date' => '2026-08-31',
            'exit_reason_code' => 'completed',
        ])->assertOk();

        $assignment->refresh();
        $this->assertSame('2026-08-31', $assignment->end_date->toDateString());
        $this->assertSame('completed', $assignment->exit_reason_code);

        $this->assertDatabaseHas('church_audit_logs', [
            'tenant_id' => $ctx['tenant']->id,
            'event' => 'leadership.terminated',
            'target_id' => $assignment->id,
        ]);
    }
}
