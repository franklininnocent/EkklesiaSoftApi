<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\ContributionPlanAssignment;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Services\ActionCenterService;
use Modules\Donations\Services\DonationDashboardService;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;

class DonationQueryShapeTest extends DonationsCertificationTestCase
{
    #[Test]
    public function missing_commitments_use_one_grouped_payment_sum(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $seed = $this->seedDue($ctx['tenant']->id, null, '50.00');
        $second = Family::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'status' => 'active',
        ]);
        $third = Family::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'status' => 'active',
        ]);

        foreach ([$seed['family'], $second, $third] as $family) {
            ContributionPlanAssignment::create([
                'tenant_id' => $ctx['tenant']->id,
                'plan_id' => $seed['plan']->id,
                'family_id' => $family->id,
                'amount' => '50.00',
                'effective_from' => now()->startOfYear()->toDateString(),
                'status' => 'active',
            ]);
        }

        DonationPayment::create([
            'tenant_id' => $ctx['tenant']->id,
            'family_id' => $seed['family']->id,
            'payment_number' => 'PAY-SHAPE-1',
            'payer_name' => 'Paying Family',
            'payment_date' => now()->toDateString(),
            'amount' => '25.00',
            'refunded_amount' => '0.00',
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);
        DonationPayment::create([
            'tenant_id' => $ctx['tenant']->id,
            'family_id' => $seed['family']->id,
            'payment_number' => 'PAY-SHAPE-2',
            'payer_name' => 'Paying Family',
            'payment_date' => now()->toDateString(),
            'amount' => '10.00',
            'refunded_amount' => '0.00',
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'voluntary',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $queues = app(ActionCenterService::class)->buildQueues((int) $ctx['tenant']->id);
        $missing = collect($queues)->firstWhere('key', 'missing_commitments');

        $this->assertNotNull($missing);
        $this->assertSame(2, $missing['affected_count']);
        $this->assertSame(100.0, $missing['expected_amount']);

        $paymentSums = collect(DB::getQueryLog())->filter(function (array $query): bool {
            return str_contains($query['query'], 'donation_payments')
                && str_contains(strtolower($query['query']), 'sum(');
        });
        $this->assertLessThanOrEqual(4, $paymentSums->count());

        $summary = app(DonationDashboardService::class)->getSummary((int) $ctx['tenant']->id);
        $this->assertSame(35.0, $summary['totals']['collected']);
        $this->assertSame(10.0, $summary['totals']['voluntary_collected']);
        $this->assertSame(1, $summary['families']['participating_last_90_days']);
    }
}
