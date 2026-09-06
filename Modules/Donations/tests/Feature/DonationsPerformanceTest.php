<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class DonationsPerformanceTest extends DonationsCertificationTestCase
{
    #[Test]
    public function large_volume_list_and_dashboard_stay_within_query_budget(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $seed = $this->seedDue($ctx['tenant']->id, null, '25.00');
        $now = now();
        $payments = [];
        $dues = [];

        for ($i = 0; $i < 400; $i++) {
            $payments[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $ctx['tenant']->id,
                'family_id' => $seed['family']->id,
                'payment_number' => 'PAY-PERF-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'payer_name' => 'Volume Payer',
                'payment_date' => $now->toDateString(),
                'amount' => '10.25',
                'refunded_amount' => '0.00',
                'currency' => 'INR',
                'method' => 'cash',
                'status' => 'succeeded',
                'source_type' => 'general',
                'is_anonymous' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        for ($i = 0; $i < 250; $i++) {
            $dues[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $ctx['tenant']->id,
                'family_id' => $seed['family']->id,
                'plan_id' => $seed['plan']->id,
                'period_label' => 'VOL-'.$i,
                'due_date' => $now->toDateString(),
                'amount_due' => '25.00',
                'amount_paid' => '0.00',
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($payments, 100) as $chunk) {
            DonationPayment::insert($chunk);
        }
        foreach (array_chunk($dues, 100) as $chunk) {
            ContributionDue::insert($chunk);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $list = $this->getJson('/api/tenant/donations/payments?per_page=20')->assertOk();
        $listQueries = count(DB::getQueryLog());
        $this->assertLessThan(25, $listQueries, 'Payment list should eager-load related rows. Queries: '.$listQueries);
        $this->assertSame(400, (int) $list->json('data.total'));

        DB::flushQueryLog();
        $summary = $this->getJson('/api/tenant/donations/dashboard/summary')->assertOk();
        $dashboardQueries = count(DB::getQueryLog());
        $this->assertLessThan(120, $dashboardQueries, 'Dashboard query budget exceeded: '.$dashboardQueries);
        $this->assertTrue(MoneyMath::equals($summary->json('data.totals.collected'), '4100.00'));

        DB::flushQueryLog();
        $this->getJson('/api/tenant/donations/families/'.$seed['family']->id.'/financial-profile')->assertOk();
        $profileQueries = count(DB::getQueryLog());
        $this->assertLessThan(50, $profileQueries, 'Family profile query budget exceeded: '.$profileQueries);
    }
}
