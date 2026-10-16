<?php

namespace Modules\Subscriptions\Services\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Models\BillingInvoice;
use Modules\Subscriptions\Models\BillingInvoiceLine;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Models\BillingPaymentAttempt;
use Modules\Subscriptions\Models\BillingReceipt;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Services\SubscriptionPlanChangeService;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;

final class BillingFulfillmentService
{
    public function __construct(
        private readonly SubscriptionPlanChangeService $planChanges,
        private readonly SubscriptionService $subscriptionService,
        private readonly BillingAuditService $audit,
        private readonly BillingPromotionRedemptionService $redemptions,
        private readonly BillingNotificationPublisher $notifications,
    ) {}

    public function fulfillFromOrder(BillingOrder $order, BillingPaymentAttempt $attempt, ?int $actorId): void
    {
        $wasFulfilled = $order->status === BillingOrder::STATUS_FULFILLED;
        $fulfillmentKey = 'order_'.$order->id.'_pay_'.$attempt->provider_payment_id;
        if ($order->fulfillment_key !== null && $order->fulfillment_key !== $fulfillmentKey) {
            $order->forceFill(['status' => BillingOrder::STATUS_NEEDS_INVESTIGATION])->save();

            return;
        }

        if ($order->status === BillingOrder::STATUS_FULFILLED) {
            return;
        }

        DB::transaction(function () use ($order, $attempt, $actorId, $fulfillmentKey) {
            $locked = BillingOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === BillingOrder::STATUS_FULFILLED) {
                return;
            }

            $tenant = Tenant::query()->whereKey($locked->tenant_id)->lockForUpdate()->firstOrFail();
            $plan = Plan::query()->findOrFail($locked->plan_id);
            $durationMonths = $locked->billing_interval === 'ANNUAL' ? 12 : 1;

            $currentPlanCode = $tenant->plan;
            $isRenewal = $plan->key === $currentPlanCode || $plan->code === $currentPlanCode;

            if ($isRenewal && $tenant->subscription_ends_at !== null) {
                $this->subscriptionService->renew($tenant, [
                    'duration_months' => $durationMonths,
                    'source' => 'billing_payment',
                    'reason' => 'Paid renewal '.$locked->internal_reference,
                ], $actorId, 'tenant_admin');
            } else {
                $actor = $actorId ? User::query()->find($actorId) : null;
                $this->planChanges->assign($tenant, $plan, [
                    'billing_interval' => $locked->billing_interval,
                    'contracted_price' => $locked->net_amount,
                    'duration_months' => $durationMonths,
                    'source' => TenantSubscription::SOURCE_BILLING_PAYMENT,
                    'reason' => 'Online payment '.$locked->internal_reference,
                    'confirm_impact' => true,
                ], $actor);

                if ($tenant->trial_ends_at !== null && $tenant->trial_ends_at->isFuture()) {
                    $tenant->forceFill(['trial_ends_at' => now()])->save();
                }
            }

            $invoice = $this->issueInvoice($locked, $tenant);
            $receipt = BillingReceipt::query()->create([
                'tenant_id' => $tenant->id,
                'source' => BillingReceipt::SOURCE_ONLINE,
                'billing_payment_attempt_id' => $attempt->id,
                'amount' => $locked->gross_amount,
                'currency_code' => $locked->currency_code,
                'status' => BillingReceipt::STATUS_FULLY_ALLOCATED,
                'external_reference' => $attempt->provider_payment_id,
                'confirmed_by' => $actorId,
                'confirmed_at' => now(),
            ]);

            $invoice->forceFill([
                'amount_paid' => $locked->gross_amount,
                'status' => BillingInvoice::STATUS_PAID,
            ])->save();

            $locked->forceFill([
                'status' => BillingOrder::STATUS_FULFILLED,
                'fulfillment_key' => $fulfillmentKey,
                'fulfilled_at' => now(),
            ])->save();

            $this->redemptions->consumeForOrder($locked);

            $this->audit->record('billing_fulfilled', $actorId, null, (int) $tenant->id, null, null, [
                'order_id' => $locked->id,
                'invoice_id' => $invoice->id,
                'receipt_id' => $receipt->id,
            ]);
        });

        if (! $wasFulfilled) {
            $fresh = $order->fresh();
            if ($fresh && $fresh->status === BillingOrder::STATUS_FULFILLED) {
                $tenant = Tenant::query()->find($fresh->tenant_id);
                if ($tenant) {
                    $this->notifications->paymentFulfilled($tenant, $fresh);
                }
            }
        }
    }

    public function issueInvoiceForOrder(BillingOrder $order, Tenant $tenant): BillingInvoice
    {
        return $this->issueInvoice($order, $tenant);
    }

    private function issueInvoice(BillingOrder $order, Tenant $tenant): BillingInvoice
    {
        $existing = BillingInvoice::query()->where('billing_order_id', $order->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $number = 'INV-'.strtoupper(Str::random(10));
        $invoice = BillingInvoice::query()->create([
            'tenant_id' => $tenant->id,
            'billing_order_id' => $order->id,
            'invoice_number' => $number,
            'status' => BillingInvoice::STATUS_ISSUED,
            'net_amount' => $order->net_amount,
            'tax_amount' => $order->tax_amount,
            'gross_amount' => $order->gross_amount,
            'amount_paid' => '0.00',
            'currency_code' => $order->currency_code,
            'tax_snapshot' => $order->tax_snapshot,
            'issued_at' => now(),
        ]);

        $snapshot = (array) $order->commercial_snapshot;
        BillingInvoiceLine::query()->create([
            'billing_invoice_id' => $invoice->id,
            'description' => ($snapshot['plan']['name'] ?? 'Subscription').' ('.$order->billing_interval.')',
            'quantity' => '1.00',
            'unit_amount' => $order->net_amount,
            'tax_amount' => $order->tax_amount,
            'line_total' => $order->gross_amount,
        ]);

        return $invoice;
    }
}
