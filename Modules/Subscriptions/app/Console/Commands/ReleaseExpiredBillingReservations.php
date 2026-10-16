<?php

namespace Modules\Subscriptions\Console\Commands;

use Illuminate\Console\Command;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Models\BillingPromotionRedemption;

class ReleaseExpiredBillingReservations extends Command
{
    protected $signature = 'billing:release-expired-reservations';

    protected $description = 'Release promotion redemptions reserved for expired unpaid billing orders';

    public function handle(): int
    {
        $released = 0;
        $rows = BillingPromotionRedemption::query()
            ->where('status', BillingPromotionRedemption::STATUS_RESERVED)
            ->whereNotNull('billing_order_id')
            ->get();

        foreach ($rows as $row) {
            $order = BillingOrder::query()->find($row->billing_order_id);
            if ($order === null) {
                continue;
            }
            if ($order->expires_at !== null && $order->expires_at->isPast()
                && $order->status !== BillingOrder::STATUS_FULFILLED) {
                $row->forceFill(['status' => BillingPromotionRedemption::STATUS_RELEASED])->save();
                $released++;
            }
        }

        $this->info("Released {$released} promotion reservation(s).");

        return self::SUCCESS;
    }
}
