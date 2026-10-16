<?php

namespace Modules\Donations\Tests\Unit;

use Illuminate\Support\Facades\Log;
use Modules\Donations\Services\ReportDrillDown\ReportDrillDownReconciliationAuditor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportDrillDownReconciliationAuditorTest extends TestCase
{
    #[Test]
    public function it_logs_when_unfiltered_payment_total_does_not_match_chart(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'report.drill_down.reconciliation_mismatch'
                    && ($context['expected_amount'] ?? null) === 100.0
                    && ($context['summary_amount_total'] ?? null) === 90.0;
            });

        $auditor = new ReportDrillDownReconciliationAuditor;
        $auditor->audit(1, [
            'context' => [
                'graph_id' => 'collections',
                'slice_id' => 'current_month',
                'value_kind' => 'money',
                'record_kind' => 'payment',
                'expected_amount' => 100,
            ],
            'summary' => [
                'amount_total' => 90,
            ],
            'data' => ['total' => 1],
        ], [
            'page' => 1,
        ]);
    }
}
