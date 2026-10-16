<?php

namespace Modules\BCC\Tests\Feature;

use Modules\BCC\Exceptions\BccDomainException;
use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BCCLeader;
use Modules\BCC\Services\BccLeadershipService;
use Modules\BCC\Testing\BccCertificationTestCase;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;

class BccLeadershipTenantIsolationTest extends BccCertificationTestCase
{
    #[Test]
    public function terminate_does_not_end_leadership_in_another_tenant(): void
    {
        $tenantA = Tenant::factory()->active()->create();
        $tenantB = Tenant::factory()->active()->create();

        $bccA = BCC::factory()->active()->create(['tenant_id' => $tenantA->id]);
        $bccB = BCC::factory()->active()->create(['tenant_id' => $tenantB->id]);
        $familyB = Family::factory()->create(['tenant_id' => $tenantB->id, 'bcc_id' => $bccB->id, 'status' => 'active']);
        $memberB = FamilyMember::factory()->create(['tenant_id' => $tenantB->id, 'family_id' => $familyB->id, 'status' => 'active']);
        $leaderB = BCCLeader::factory()->create([
            'tenant_id' => $tenantB->id,
            'bcc_id' => $bccB->id,
            'family_member_id' => $memberB->id,
            'role' => 'leader',
            'is_active' => true,
            'term_start_date' => now()->subMonth()->toDateString(),
        ]);

        $service = app(BccLeadershipService::class);

        try {
            $service->terminate($tenantA->id, $bccA->id, $leaderB->id, [
                'effective_to' => now()->toDateString(),
                'exit_reason' => 'resigned',
            ]);
            $this->fail('Expected not found when terminating foreign leadership.');
        } catch (BccDomainException $e) {
            $this->assertSame(404, $e->httpStatus);
        }

        $this->assertTrue($leaderB->fresh()->is_active);
    }

    #[Test]
    public function api_terminate_returns_not_found_for_foreign_bcc_leadership(): void
    {
        $this->actingAsTenantWith(['bcc.view', 'bcc.manage_leadership']);
        $tenantB = Tenant::factory()->active()->create();
        $bccB = BCC::factory()->active()->create(['tenant_id' => $tenantB->id]);
        $familyB = Family::factory()->create(['tenant_id' => $tenantB->id, 'bcc_id' => $bccB->id, 'status' => 'active']);
        $memberB = FamilyMember::factory()->create(['tenant_id' => $tenantB->id, 'family_id' => $familyB->id, 'status' => 'active']);
        $leaderB = BCCLeader::factory()->create([
            'tenant_id' => $tenantB->id,
            'bcc_id' => $bccB->id,
            'family_member_id' => $memberB->id,
            'role' => 'leader',
            'is_active' => true,
            'term_start_date' => now()->subMonth()->toDateString(),
        ]);

        $this->postJson("/api/bccs/{$bccB->id}/leadership/{$leaderB->id}/terminate", [
            'effective_to' => now()->toDateString(),
            'exit_reason' => 'resigned',
        ])->assertNotFound();

        $this->assertTrue($leaderB->fresh()->is_active);
    }
}
