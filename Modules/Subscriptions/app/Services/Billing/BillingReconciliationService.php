<?php

namespace Modules\Subscriptions\Services\Billing;

use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Models\BillingPaymentAttempt;
use Modules\Subscriptions\Models\BillingWebhookEvent;

final class BillingReconciliationService
{
    /**
     * @return array{status: string, summary: array<string, int|list<string>>}
     */
    public function run(?int $startedBy): array
    {
        $runId = DB::table('billing_reconciliation_runs')->insertGetId([
            'status' => 'running',
            'started_by' => $startedBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stuckAttempts = BillingPaymentAttempt::query()
            ->whereIn('status', ['created', 'authorized', 'pending'])
            ->where('created_at', '<', now()->subHours(24))
            ->count();

        $stuckOrders = BillingOrder::query()
            ->where('status', BillingOrder::STATUS_AWAITING_PAYMENT)
            ->where('expires_at', '<', now())
            ->count();

        $failedWebhooks = BillingWebhookEvent::query()
            ->where('processing_status', 'failed')
            ->count();

        $summary = [
            'stuck_payment_attempts' => $stuckAttempts,
            'expired_unpaid_orders' => $stuckOrders,
            'failed_webhook_events' => $failedWebhooks,
        ];

        DB::table('billing_reconciliation_runs')->where('id', $runId)->update([
            'status' => 'completed',
            'summary' => json_encode($summary),
            'completed_at' => now(),
            'updated_at' => now(),
        ]);

        return ['status' => 'completed', 'summary' => $summary, 'run_id' => $runId];
    }
}
