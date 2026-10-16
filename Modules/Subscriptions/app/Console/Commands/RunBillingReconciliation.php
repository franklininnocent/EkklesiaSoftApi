<?php

namespace Modules\Subscriptions\Console\Commands;

use Illuminate\Console\Command;
use Modules\Subscriptions\Services\Billing\BillingReconciliationService;

class RunBillingReconciliation extends Command
{
    protected $signature = 'billing:reconcile';

    protected $description = 'Run SaaS billing reconciliation counters (stuck payments, expired orders, failed webhooks)';

    public function handle(BillingReconciliationService $reconciliation): int
    {
        $result = $reconciliation->run(null);
        $this->info('Reconciliation '.$result['status']);
        foreach ($result['summary'] as $key => $value) {
            if (is_scalar($value)) {
                $this->line("  {$key}: {$value}");
            }
        }

        return self::SUCCESS;
    }
}
