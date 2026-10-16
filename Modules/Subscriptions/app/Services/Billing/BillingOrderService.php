<?php

namespace Modules\Subscriptions\Services\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Billing\BillingAction;
use Modules\Subscriptions\Billing\BillingAvailabilityPolicy;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\BillingGatewayConfig;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Services\SubscriptionPolicyService;
use Modules\Tenants\Models\Tenant;

final class BillingOrderService
{
    public function __construct(
        private readonly BillingAvailabilityPolicy $availability,
        private readonly BillingQuoteService $quotes,
        private readonly RazorpayGatewayClient $razorpay,
        private readonly SubscriptionPolicyService $policies,
        private readonly BillingAuditService $audit,
        private readonly BillingFulfillmentService $fulfillment,
        private readonly BillingPromotionRedemptionService $redemptions,
    ) {}

    /**
     * @return array{order: BillingOrder, checkout: array<string, mixed>}
     */
    public function createOrder(Tenant $tenant, User $actor, string $planCode, string $billingInterval, string $idempotencyKey): array
    {
        $decision = $this->availability->decide($tenant, BillingAction::CreateOnlineOrder);
        if (! $decision->allowed) {
            throw SubscriptionException::billingDenied($decision->reasonCode, $decision->message);
        }

        $existing = BillingOrder::query()
            ->where('tenant_id', $tenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            return $this->presentCheckout($existing);
        }

        $quote = $this->quotes->quote($tenant, $planCode, $billingInterval);
        $billing = (array) $this->policies->get('billing', []);
        $ttlMinutes = max(5, (int) ($billing['order_ttl_minutes'] ?? 60));
        $environment = $this->resolveEnvironment();

        $config = BillingGatewayConfig::query()->where('environment', $environment)->first();
        if ($config === null || ! $config->enabled || $config->public_key_id === null) {
            throw SubscriptionException::billingDenied('gateway_unavailable', 'Online payment is not configured.');
        }

        $receipt = 'ekn_'.Str::lower(Str::random(16));
        $internalRef = 'BILL-'.strtoupper(Str::random(12));
        $amountMinor = BillingQuoteService::toMinorUnits($quote['gross'], $quote['currency_code']);

        return DB::transaction(function () use ($tenant, $actor, $quote, $billingInterval, $idempotencyKey, $ttlMinutes, $environment, $config, $receipt, $internalRef, $amountMinor) {
            $order = BillingOrder::query()->create([
                'tenant_id' => $tenant->id,
                'created_by_user_id' => $actor->id,
                'plan_version_id' => $quote['plan']['version_id'],
                'plan_id' => $quote['plan']['id'],
                'billing_interval' => $billingInterval,
                'idempotency_key' => $idempotencyKey,
                'internal_reference' => $internalRef,
                'receipt' => $receipt,
                'environment' => $environment,
                'provider' => 'razorpay',
                'status' => BillingOrder::STATUS_DRAFT,
                'net_amount' => $quote['net'],
                'tax_amount' => $quote['tax'],
                'gross_amount' => $quote['gross'],
                'amount_minor' => $amountMinor,
                'currency_code' => $quote['currency_code'],
                'tax_snapshot' => [
                    'label' => $quote['tax_label'],
                    'rate_percent' => $quote['tax_rate_percent'],
                    'prices_include_tax' => $quote['prices_include_tax'],
                ],
                'commercial_snapshot' => $quote,
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);

            try {
                $providerOrder = $this->razorpay->createOrder($config, $amountMinor, $quote['currency_code'], $receipt);
            } catch (\Throwable $e) {
                $order->forceFill(['status' => BillingOrder::STATUS_FAILED])->save();
                throw SubscriptionException::billingDenied('provider_error', 'Unable to start checkout. Please try again.');
            }

            $this->redemptions->reserveForOrder($tenant, $quote, $order);

            $order->forceFill([
                'provider_order_id' => (string) ($providerOrder['id'] ?? ''),
                'status' => BillingOrder::STATUS_AWAITING_PAYMENT,
            ])->save();

            $this->audit->record('billing_order_created', $actor->id, null, (int) $tenant->id, null, null, [
                'order_id' => $order->id,
                'internal_reference' => $order->internal_reference,
            ]);

            return $this->presentCheckout($order->fresh(), $config);
        });
    }

    /**
     * @return array{order: BillingOrder, checkout: array<string, mixed>}
     */
    private function presentCheckout(BillingOrder $order, ?BillingGatewayConfig $config = null): array
    {
        $environment = $order->environment;
        $config ??= BillingGatewayConfig::query()->where('environment', $environment)->first();

        return [
            'order' => $order,
            'checkout' => [
                'public_key_id' => $config?->public_key_id,
                'provider_order_id' => $order->provider_order_id,
                'amount_minor' => $order->amount_minor,
                'currency_code' => $order->currency_code,
                'internal_reference' => $order->internal_reference,
                'status' => $order->status,
                'expires_at' => $order->expires_at?->toIso8601String(),
            ],
        ];
    }

    private function resolveEnvironment(): string
    {
        $test = BillingGatewayConfig::query()->where('environment', 'test')->where('enabled', true)->exists();
        $live = BillingGatewayConfig::query()->where('environment', 'live')->where('live_checkout_enabled', true)->exists();

        return $live ? 'live' : 'test';
    }

    /**
     * Creates a payable order and issued invoice without Razorpay (offline / manual settlement).
     *
     * @return array{order: BillingOrder, invoice_id: int}
     */
    public function createPayableOrder(Tenant $tenant, User $actor, string $planCode, string $billingInterval, string $idempotencyKey): array
    {
        $decision = $this->availability->decide($tenant, BillingAction::QuotePurchase);
        if (! $decision->allowed) {
            throw SubscriptionException::billingDenied($decision->reasonCode, $decision->message);
        }

        $existing = BillingOrder::query()
            ->where('tenant_id', $tenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            $invoice = \Modules\Subscriptions\Models\BillingInvoice::query()
                ->where('billing_order_id', $existing->id)
                ->first();

            return ['order' => $existing, 'invoice_id' => (int) ($invoice?->id ?? 0)];
        }

        $quote = $this->quotes->quote($tenant, $planCode, $billingInterval);
        $billing = (array) $this->policies->get('billing', []);
        $ttlMinutes = max(5, (int) ($billing['order_ttl_minutes'] ?? 60));
        $receipt = 'ekn_'.Str::lower(Str::random(16));
        $internalRef = 'BILL-'.strtoupper(Str::random(12));

        return DB::transaction(function () use ($tenant, $actor, $quote, $billingInterval, $idempotencyKey, $ttlMinutes, $receipt, $internalRef) {
            $order = BillingOrder::query()->create([
                'tenant_id' => $tenant->id,
                'created_by_user_id' => $actor->id,
                'plan_version_id' => $quote['plan']['version_id'],
                'plan_id' => $quote['plan']['id'],
                'billing_interval' => $billingInterval,
                'idempotency_key' => $idempotencyKey,
                'internal_reference' => $internalRef,
                'receipt' => $receipt,
                'environment' => $this->resolveEnvironment(),
                'provider' => 'internal',
                'status' => BillingOrder::STATUS_AWAITING_PAYMENT,
                'net_amount' => $quote['net'],
                'tax_amount' => $quote['tax'],
                'gross_amount' => $quote['gross'],
                'amount_minor' => BillingQuoteService::toMinorUnits($quote['gross'], $quote['currency_code']),
                'currency_code' => $quote['currency_code'],
                'tax_snapshot' => [
                    'label' => $quote['tax_label'],
                    'rate_percent' => $quote['tax_rate_percent'],
                    'prices_include_tax' => $quote['prices_include_tax'],
                ],
                'commercial_snapshot' => $quote,
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);

            $this->redemptions->reserveForOrder($tenant, $quote, $order);

            $invoice = $this->fulfillment->issueInvoiceForOrder($order, $tenant);

            $this->audit->record('payable_order_created', $actor->id, null, (int) $tenant->id, null, null, [
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
            ]);

            return ['order' => $order, 'invoice_id' => $invoice->id];
        });
    }
}
