<?php

namespace Modules\Tenants\Tests\Feature\ChurchProfile;

use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BCCLeader;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Testing\ChurchProfileCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class ChurchProfileStatusMetricsTest extends ChurchProfileCertificationTestCase
{
    #[Test]
    public function it_returns_unavailable_metrics_when_parish_has_no_families_or_members(): void
    {
        $ctx = $this->actingAsTenantWith([]);
        $this->seedChurchProfile($ctx['tenant']);

        $response = $this->getJson('/api/church-profile/status-metrics');

        $response->assertOk()
            ->assertJsonPath('data.membership_health.status', 'unavailable')
            ->assertJsonPath('data.membership_health.display', 'Not available')
            ->assertJsonPath('data.sacramental_records.status', 'unavailable')
            ->assertJsonPath('data.volunteer_engagement.status', 'unavailable')
            ->assertJsonPath('data.profile_completeness.status', 'available');

        $this->assertNull($response->json('data.membership_health.percent'));
        $this->assertNull($response->json('data.sacramental_records.percent'));
        $this->assertNull($response->json('data.volunteer_engagement.percent'));
        $this->assertIsInt($response->json('data.profile_completeness.percent'));
    }

    #[Test]
    public function it_calculates_membership_health_from_active_member_ratio(): void
    {
        $ctx = $this->actingAsTenantWith([]);
        $this->seedChurchProfile($ctx['tenant']);

        $family = Family::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);
        FamilyMember::factory()->create(['family_id' => $family->id, 'status' => 'active']);
        FamilyMember::factory()->create(['family_id' => $family->id, 'status' => 'inactive']);

        $response = $this->getJson('/api/church-profile/status-metrics');

        $response->assertOk()
            ->assertJsonPath('data.membership_health.status', 'available')
            ->assertJsonPath('data.membership_health.percent', 50);
    }

    #[Test]
    public function it_calculates_sacramental_records_from_member_and_register_data(): void
    {
        $ctx = $this->actingAsTenantWith([]);
        $this->seedChurchProfile($ctx['tenant']);

        $family = Family::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'status' => 'active',
            'baptism_date' => '2020-01-01',
        ]);
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/church-profile/status-metrics');

        $response->assertOk()
            ->assertJsonPath('data.sacramental_records.status', 'available')
            ->assertJsonPath('data.sacramental_records.percent', 50);
    }

    #[Test]
    public function it_calculates_volunteer_engagement_from_bcc_leader_and_family_assignment(): void
    {
        $ctx = $this->actingAsTenantWith([]);
        $this->seedChurchProfile($ctx['tenant']);

        $bcc = BCC::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'status' => 'active',
        ]);

        $assignedFamily = Family::factory()->active()->create([
            'tenant_id' => $ctx['tenant']->id,
            'bcc_id' => $bcc->id,
        ]);
        Family::factory()->active()->create(['tenant_id' => $ctx['tenant']->id, 'bcc_id' => null]);
        FamilyMember::factory()->create(['family_id' => $assignedFamily->id, 'status' => 'active']);

        $member = FamilyMember::factory()->create([
            'family_id' => $assignedFamily->id,
            'status' => 'active',
        ]);

        BCCLeader::factory()->create([
            'bcc_id' => $bcc->id,
            'family_member_id' => $member->id,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/church-profile/status-metrics');

        $response->assertOk()
            ->assertJsonPath('data.volunteer_engagement.status', 'available');

        $this->assertGreaterThan(0, $response->json('data.volunteer_engagement.percent'));
    }

    #[Test]
    public function it_calculates_profile_completeness_from_required_fields(): void
    {
        $ctx = $this->actingAsTenantWith([]);
        $tenant = $ctx['tenant'];
        $tenant->update(['name' => 'Complete Parish', 'logo_url' => 'logo.png']);

        $this->seedChurchProfile($tenant, [
            'founded_year' => 1980,
            'phone' => '+911234567890',
            'email' => 'parish@example.test',
            'website' => 'https://example.test',
            'about' => 'Established parish narrative.',
            'patron_name' => 'St Mary',
        ]);

        $response = $this->getJson('/api/church-profile/status-metrics');

        $response->assertOk()
            ->assertJsonPath('data.profile_completeness.status', 'available');

        $this->assertGreaterThanOrEqual(50, $response->json('data.profile_completeness.percent'));
    }

    #[Test]
    public function it_does_not_leak_other_tenant_status_metrics(): void
    {
        $ctx = $this->actingAsTenantWith([]);
        $this->seedChurchProfile($ctx['tenant']);

        $otherTenant = Tenant::factory()->active()->create();
        $otherFamily = Family::factory()->active()->create(['tenant_id' => $otherTenant->id]);
        FamilyMember::factory()->count(3)->create([
            'family_id' => $otherFamily->id,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/church-profile/status-metrics');

        $response->assertOk()
            ->assertJsonPath('data.membership_health.status', 'unavailable')
            ->assertJsonPath('data.membership_health.details.total_members', 0);
    }

    #[Test]
    public function unauthenticated_requests_are_denied(): void
    {
        $this->getJson('/api/church-profile/status-metrics')->assertUnauthorized();
    }
}
