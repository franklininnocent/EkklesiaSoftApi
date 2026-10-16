<?php

namespace Modules\Subscriptions\Services\Billing;

use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Models\BillingPromotion;
use Modules\Subscriptions\Models\BillingPromotionRedemption;
use Modules\Tenants\Models\Tenant;

final class BillingPromotionRedemptionService
{
    /**
     * @param  array<string, mixed>  $quote
     */
    public function reserveForOrder(Tenant $tenant, array $quote, BillingOrder $order): void
    {
        $discount = $quote['discount'] ?? null;
        if (! is_array($discount) || ($discount['type'] ?? '') !== 'promotion') {
            return;
        }

        $promotionId = (int) ($discount['promotion_id'] ?? 0);
        if ($promotionId <= 0) {
            return;
        }

        DB::transaction(function () use ($tenant, $order, $promotionId) {
            $promo = BillingPromotion::query()->whereKey($promotionId)->lockForUpdate()->first();
            if ($promo === null || $promo->status !== BillingPromotion::STATUS_ACTIVE) {
                throw SubscriptionException::billingDenied('promotion_unavailable', 'This promotion is no longer available.');
            }

            if ($promo->max_redemptions !== null && $promo->redemption_count >= $promo->max_redemptions) {
                throw SubscriptionException::billingDenied('promotion_exhausted', 'This promotion has reached its redemption limit.');
            }

            $exists = BillingPromotionRedemption::query()
                ->where('billing_order_id', $order->id)
                ->exists();
            if ($exists) {
                return;
            }

            BillingPromotionRedemption::query()->create([
                'billing_promotion_id' => $promo->id,
                'tenant_id' => $tenant->id,
                'billing_order_id' => $order->id,
                'status' => BillingPromotionRedemption::STATUS_RESERVED,
            ]);
        });
    }

    public function consumeForOrder(BillingOrder $order): void
    {
        $discount = is_array($order->commercial_snapshot) ? ($order->commercial_snapshot['discount'] ?? null) : null;
        if (! is_array($discount) || ($discount['type'] ?? '') !== 'promotion') {
            return;
        }

        DB::transaction(function () use ($order) {
            $redemption = BillingPromotionRedemption::query()
                ->where('billing_order_id', $order->id)
                ->where('status', BillingPromotionRedemption::STATUS_RESERVED)
                ->lockForUpdate()
                ->first();
            if ($redemption === null) {
                return;
            }

            $promo = BillingPromotion::query()->whereKey($redemption->billing_promotion_id)->lockForUpdate()->first();
            if ($promo === null) {
                return;
            }

            $promo->forceFill(['redemption_count' => (int) $promo->redemption_count + 1])->save();
            $redemption->forceFill([
                'status' => BillingPromotionRedemption::STATUS_CONSUMED,
                'consumed_at' => now(),
            ])->save();
        });
    }
}
