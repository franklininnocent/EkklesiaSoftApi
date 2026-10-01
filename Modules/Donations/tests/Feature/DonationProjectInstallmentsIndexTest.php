<?php

namespace Modules\Donations\Tests\Feature;

use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\BCC\Models\BCC;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use PHPUnit\Framework\Attributes\Test;

class DonationProjectInstallmentsIndexTest extends DonationsCertificationTestCase
{
    #[Test]
    public function it_rejects_invalid_sort_on_project_installments_index(): void
    {
        $this->actingAsTenantWith(['donations.view']);

        $this->getJson('/api/tenant/donations/project-installments?sort=invalid')
            ->assertStatus(422);
    }

    #[Test]
    public function it_sorts_project_installments_by_family_name(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;

        $familyA = Family::factory()->create([
            'tenant_id' => $tenantId,
            'family_name' => 'Alpha Family',
            'head_of_family' => 'Aaron Alpha',
            'status' => 'active',
        ]);
        $familyB = Family::factory()->create([
            'tenant_id' => $tenantId,
            'family_name' => 'Zulu Family',
            'head_of_family' => 'Zach Zulu',
            'status' => 'active',
        ]);
        $project = DonationProject::create([
            'tenant_id' => $tenantId,
            'name' => 'Hall Fund',
            'code' => 'HALL',
            'assignment_mode' => 'individual',
            'default_family_target' => 1000,
            'status' => 'active',
        ]);

        ProjectInstallmentDue::create([
            'tenant_id' => $tenantId,
            'project_id' => $project->id,
            'family_id' => $familyB->id,
            'installment_number' => 1,
            'installment_label' => 'Inst 1',
            'due_date' => now()->addDays(10)->toDateString(),
            'amount_due' => 500,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);
        ProjectInstallmentDue::create([
            'tenant_id' => $tenantId,
            'project_id' => $project->id,
            'family_id' => $familyA->id,
            'installment_number' => 1,
            'installment_label' => 'Inst 1',
            'due_date' => now()->addDays(5)->toDateString(),
            'amount_due' => 500,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/tenant/donations/project-installments?sort=family_name&direction=asc&per_page=100')
            ->assertOk();

        $heads = collect($response->json('data.data'))->pluck('family.head_of_family')->all();
        $this->assertSame(['Aaron Alpha', 'Zach Zulu'], $heads);
    }

    #[Test]
    public function it_defaults_project_installments_sort_to_outstanding_desc(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;

        $family = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        $project = DonationProject::create([
            'tenant_id' => $tenantId,
            'name' => 'Hall Fund',
            'code' => 'HALL2',
            'assignment_mode' => 'individual',
            'default_family_target' => 1000,
            'status' => 'active',
        ]);

        ProjectInstallmentDue::create([
            'tenant_id' => $tenantId,
            'project_id' => $project->id,
            'family_id' => $family->id,
            'installment_number' => 2,
            'installment_label' => 'Smaller balance',
            'due_date' => now()->addDays(30)->toDateString(),
            'amount_due' => 200,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);
        ProjectInstallmentDue::create([
            'tenant_id' => $tenantId,
            'project_id' => $project->id,
            'family_id' => $family->id,
            'installment_number' => 1,
            'installment_label' => 'Larger balance',
            'due_date' => now()->addDays(5)->toDateString(),
            'amount_due' => 800,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/tenant/donations/project-installments?per_page=100')->assertOk();
        $labels = collect($response->json('data.data'))->pluck('installment_label')->all();
        $this->assertSame(['Larger balance', 'Smaller balance'], $labels);
    }

    #[Test]
    public function it_includes_family_head_name_distinct_from_household_family_name(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;

        $family = Family::factory()->create([
            'tenant_id' => $tenantId,
            'family_name' => 'Ward Household',
            'head_of_family' => 'Stale Head Label',
            'status' => 'active',
        ]);
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'John',
            'middle_name' => null,
            'last_name' => 'Peter',
            'relationship_to_head' => 'self',
            'status' => 'active',
        ]);
        $project = DonationProject::create([
            'tenant_id' => $tenantId,
            'name' => 'Roof Fund',
            'code' => 'ROOF',
            'assignment_mode' => 'individual',
            'default_family_target' => 1000,
            'status' => 'active',
        ]);
        ProjectInstallmentDue::create([
            'tenant_id' => $tenantId,
            'project_id' => $project->id,
            'family_id' => $family->id,
            'installment_number' => 1,
            'installment_label' => 'Inst 1',
            'due_date' => now()->addDays(7)->toDateString(),
            'amount_due' => 100,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/tenant/donations/project-installments?per_page=100')->assertOk();
        $row = $response->json('data.data.0');

        $this->assertSame('John Peter', $row['family_head_name']);
        $this->assertSame('Ward Household', $row['family']['family_name']);
        $this->assertNotSame($row['family']['family_name'], $row['family_head_name']);
    }

    #[Test]
    public function it_filters_project_installments_by_bcc_and_rejects_cross_bcc_family(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;

        $bccA = BCC::factory()->active()->create(['tenant_id' => $tenantId]);
        $bccB = BCC::factory()->active()->create(['tenant_id' => $tenantId]);
        $familyA = Family::factory()->create([
            'tenant_id' => $tenantId,
            'status' => 'active',
            'bcc_id' => $bccA->id,
            'head_of_family' => 'Aaron Alpha',
        ]);
        $familyB = Family::factory()->create([
            'tenant_id' => $tenantId,
            'status' => 'active',
            'bcc_id' => $bccB->id,
            'head_of_family' => 'Zach Zulu',
        ]);
        $project = DonationProject::create([
            'tenant_id' => $tenantId,
            'name' => 'Hall Fund',
            'code' => 'HALL-BCC',
            'assignment_mode' => 'individual',
            'default_family_target' => 1000,
            'status' => 'active',
        ]);

        foreach ([$familyA, $familyB] as $index => $family) {
            ProjectInstallmentDue::create([
                'tenant_id' => $tenantId,
                'project_id' => $project->id,
                'family_id' => $family->id,
                'installment_number' => $index + 1,
                'installment_label' => 'Inst '.($index + 1),
                'due_date' => now()->addDays(10)->toDateString(),
                'amount_due' => 100,
                'amount_paid' => 0,
                'status' => 'pending',
            ]);
        }

        $filtered = $this->getJson('/api/tenant/donations/project-installments?bcc_id='.$bccA->id.'&per_page=100')
            ->assertOk();
        $familyIds = collect($filtered->json('data.data'))->pluck('family_id')->all();
        $this->assertSame([$familyA->id], $familyIds);

        $this->getJson('/api/tenant/donations/project-installments?bcc_id='.$bccA->id.'&family_id='.$familyB->id)
            ->assertStatus(422);

        $other = $this->makeTenantUser(['donations.view']);
        $foreignBcc = BCC::factory()->active()->create(['tenant_id' => $other['tenant']->id]);
        $this->getJson('/api/tenant/donations/project-installments?bcc_id='.$foreignBcc->id)->assertStatus(422);
    }
}
