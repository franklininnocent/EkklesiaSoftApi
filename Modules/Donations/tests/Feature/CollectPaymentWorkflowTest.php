<?php

namespace Modules\Donations\Tests\Feature;

use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;

class CollectPaymentWorkflowTest extends DonationsCertificationTestCase
{
    #[Test]
    public function collect_context_returns_authoritative_due_balance_not_a_client_amount(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $seed = $this->seedDue($ctx['tenant']->id, null, '5000.00');

        $response = $this->getJson('/api/tenant/donations/payments/collect-context?'.http_build_query([
            'family_id' => $seed['family']->id,
            'due_id' => $seed['due']->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('data.family.id', $seed['family']->id)
            ->assertJsonPath('data.suggested_allocation.allocatable_type', 'due')
            ->assertJsonPath('data.suggested_allocation.allocatable_id', $seed['due']->id)
            ->assertJsonPath('data.collectible_amount', 5000)
            ->assertJsonPath('data.overpayment_becomes_credit', true);

        $this->assertNotEmpty($response->json('data.payment_date'));
        $this->assertNotEmpty($response->json('data.currency_code'));
    }

    #[Test]
    public function collect_context_rejects_cross_family_due_ids(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $seed = $this->seedDue($ctx['tenant']->id, null, '1000.00');
        $otherFamily = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);

        $this->getJson('/api/tenant/donations/payments/collect-context?'.http_build_query([
            'family_id' => $otherFamily->id,
            'due_id' => $seed['due']->id,
        ]))->assertStatus(404);
    }

    #[Test]
    public function payment_rejects_allocating_another_family_due_in_the_same_tenant(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $seed = $this->seedDue($ctx['tenant']->id, null, '1000.00');
        $otherFamily = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($otherFamily, '1000.00', [
            'allocations' => [[
                'allocatable_type' => 'due',
                'allocatable_id' => $seed['due']->id,
                'amount' => 1000,
            ]],
        ]))->assertStatus(422);

        $this->assertSame('0.00', number_format((float) $seed['due']->fresh()->amount_paid, 2, '.', ''));
    }

    #[Test]
    public function payment_rejects_already_paid_and_waived_dues(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.manage']);
        $seed = $this->seedDue($ctx['tenant']->id, null, '250.00');

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], '250.00', [
            'allocations' => [[
                'allocatable_type' => 'due',
                'allocatable_id' => $seed['due']->id,
                'amount' => 250,
            ]],
        ]))->assertCreated();

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], '250.00', [
            'allocations' => [[
                'allocatable_type' => 'due',
                'allocatable_id' => $seed['due']->id,
                'amount' => 250,
            ]],
        ]))->assertStatus(422);

        $other = $this->seedDue($ctx['tenant']->id, $seed['family'], '100.00');
        $this->postJson('/api/tenant/donations/dues/'.$other['due']->id.'/waive', [
            'reason' => 'Pastoral waiver',
        ])->assertOk();

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], '100.00', [
            'allocations' => [[
                'allocatable_type' => 'due',
                'allocatable_id' => $other['due']->id,
                'amount' => 100,
            ]],
        ]))->assertStatus(422);
    }

    #[Test]
    public function partial_payment_updates_only_the_targeted_due(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $seed = $this->seedDue($ctx['tenant']->id, null, '5000.00');
        $other = $this->seedDue($ctx['tenant']->id, Family::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'status' => 'active',
        ]), '5000.00');

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], '2000.00', [
            'allocations' => [[
                'allocatable_type' => 'due',
                'allocatable_id' => $seed['due']->id,
                'amount' => 2000,
            ]],
        ]))->assertCreated();

        $this->assertSame('2000.00', number_format((float) $seed['due']->fresh()->amount_paid, 2, '.', ''));
        $this->assertSame('partially_paid', $seed['due']->fresh()->status);
        $this->assertSame('0.00', number_format((float) $other['due']->fresh()->amount_paid, 2, '.', ''));

        $context = $this->getJson('/api/tenant/donations/payments/collect-context?'.http_build_query([
            'family_id' => $seed['family']->id,
            'due_id' => $seed['due']->id,
        ]));
        $context->assertOk()->assertJsonPath('data.collectible_amount', 3000);
    }

    #[Test]
    public function installment_collection_locks_to_the_family_and_project_on_the_row(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.manage']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $otherFamily = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);

        $projectId = $this->postJson('/api/tenant/donations/projects', [
            'name' => 'Parish Hall',
            'code' => 'HALL-'.substr(uniqid(), -6),
            'assignment_mode' => 'uniform',
            'default_family_target' => 300,
            'installment_count' => 3,
            'installment_frequency' => 'monthly',
            'start_date' => now()->toDateString(),
            'status' => 'active',
            'auto_generate_installments' => false,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")->assertOk();

        $installment = ProjectInstallmentDue::query()
            ->where('project_id', $projectId)
            ->where('family_id', $family->id)
            ->orderBy('installment_number')
            ->first();
        $this->assertNotNull($installment);

        $this->getJson('/api/tenant/donations/payments/collect-context?'.http_build_query([
            'family_id' => $family->id,
            'project_id' => $projectId,
            'installment_id' => $installment->id,
        ]))->assertOk()
            ->assertJsonPath('data.suggested_allocation.allocatable_type', 'project_installment')
            ->assertJsonPath('data.suggested_allocation.allocatable_id', $installment->id);

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($otherFamily, '100.00', [
            'allocations' => [[
                'allocatable_type' => 'project_installment',
                'allocatable_id' => $installment->id,
                'amount' => 100,
            ]],
        ]))->assertStatus(422);

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '100.00', [
            'allocations' => [[
                'allocatable_type' => 'project_installment',
                'allocatable_id' => $installment->id,
                'amount' => 100,
            ]],
        ]))->assertCreated();

        $this->assertSame('100.00', number_format((float) $installment->fresh()->amount_paid, 2, '.', ''));
        $this->assertSame(0.0, (float) ProjectInstallmentDue::query()
            ->where('project_id', $projectId)
            ->where('family_id', $otherFamily->id)
            ->sum('amount_paid'));
    }

    #[Test]
    public function collect_context_marks_waived_dues_as_not_collectable(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.manage']);
        $seed = $this->seedDue($ctx['tenant']->id, null, '100.00');

        $this->postJson('/api/tenant/donations/dues/'.$seed['due']->id.'/waive', [
            'reason' => 'Pastoral waiver',
        ])->assertOk();

        $this->getJson('/api/tenant/donations/payments/collect-context?'.http_build_query([
            'family_id' => $seed['family']->id,
            'due_id' => $seed['due']->id,
        ]))->assertOk()
            ->assertJsonPath('data.can_collect', false)
            ->assertJsonPath('data.collectible_amount', 0)
            ->assertJsonPath('data.family.id', $seed['family']->id);
    }

    #[Test]
    public function collect_context_hides_other_tenant_records(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $foreign = $this->makeTenantUser(['donations.view', 'donations.collect']);
        $seed = $this->seedDue($foreign['tenant']->id, null, '250.00');

        $this->getJson('/api/tenant/donations/payments/collect-context?'.http_build_query([
            'family_id' => $seed['family']->id,
            'due_id' => $seed['due']->id,
        ]))->assertStatus(404);
    }
}
