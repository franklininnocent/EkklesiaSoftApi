<?php

namespace Modules\Subscriptions\Billing;

use Modules\Subscriptions\Models\TenantBillingPolicy;
use Modules\Subscriptions\Services\SubscriptionPolicyService;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;

/**
 * Central evaluator for customer-facing billing actions. Fails closed when policy cannot be read.
 */
final class BillingAvailabilityPolicy
{
    public function __construct(private readonly SubscriptionPolicyService $policies) {}

    public function decide(?Tenant $tenant, BillingAction $action): BillingAvailabilityDecision
    {
        try {
            $billing = (array) $this->policies->get('billing', []);
        } catch (\Throwable) {
            return BillingAvailabilityDecision::deny('policy_unavailable', 'Billing is temporarily unavailable. Please try again later.');
        }

        if (in_array($action, [BillingAction::ViewBillingHistory, BillingAction::DownloadInvoice], true)) {
            return BillingAvailabilityDecision::allow();
        }

        if (($billing['customer_payment_actions'] ?? 'disabled') !== 'enabled') {
            return BillingAvailabilityDecision::deny(
                'customer_payments_disabled',
                'New payment actions are currently disabled. You can still view billing history.'
            );
        }

        if ($tenant !== null) {
            $tenantDecision = $this->evaluateTenantCommercial($tenant);
            if (! $tenantDecision->allowed) {
                return $tenantDecision;
            }
        }

        return match ($action) {
            BillingAction::QuotePurchase, BillingAction::CreateOnlineOrder => $this->decideOnline($billing, $tenant),
            BillingAction::VerifyOnlinePayment => BillingAvailabilityDecision::allow(),
            BillingAction::SubmitOfflineClaim => $this->decideOffline($billing, $tenant),
        };
    }

    /**
     * @param  array<string, mixed>  $billing
     */
    private function decideOnline(array $billing, ?Tenant $tenant): BillingAvailabilityDecision
    {
        $mode = (string) ($billing['online_checkout'] ?? 'disabled');
        if ($mode === 'maintenance') {
            $msg = (string) ($billing['maintenance_message'] ?? 'Online checkout is temporarily unavailable for maintenance.');
            return BillingAvailabilityDecision::deny('online_maintenance', $msg);
        }
        if ($mode !== 'enabled') {
            return BillingAvailabilityDecision::deny('online_checkout_disabled', 'Online checkout is not available.');
        }

        if ($tenant !== null) {
            $policy = TenantBillingPolicy::query()->where('tenant_id', $tenant->id)->first();
            if ($policy?->online_checkout === 'deny') {
                return BillingAvailabilityDecision::deny('tenant_online_denied', 'Online checkout is not available for your church.');
            }
        }

        return BillingAvailabilityDecision::allow();
    }

    /**
     * @param  array<string, mixed>  $billing
     */
    private function decideOffline(array $billing, ?Tenant $tenant): BillingAvailabilityDecision
    {
        if (($billing['offline_claims'] ?? 'disabled') !== 'enabled') {
            return BillingAvailabilityDecision::deny('offline_claims_disabled', 'Offline payment claims are not available.');
        }

        if ($tenant !== null) {
            $policy = TenantBillingPolicy::query()->where('tenant_id', $tenant->id)->first();
            if ($policy?->offline_claims === 'deny') {
                return BillingAvailabilityDecision::deny('tenant_offline_denied', 'Offline payment claims are not available for your church.');
            }
        }

        return BillingAvailabilityDecision::allow();
    }

    private function evaluateTenantCommercial(Tenant $tenant): BillingAvailabilityDecision
    {
        $policy = TenantBillingPolicy::query()->where('tenant_id', $tenant->id)->first();
        if ($policy?->purchases_restricted) {
            return BillingAvailabilityDecision::deny('tenant_purchases_restricted', 'New purchases are restricted for your church. Contact support.');
        }

        if (! empty($tenant->subscription_suspended_at)) {
            return BillingAvailabilityDecision::deny('tenant_suspended', 'Your church account is suspended. Contact support.');
        }

        if ($tenant->subscription_ends_at === null && $tenant->trial_ends_at === null) {
            return BillingAvailabilityDecision::deny('tenant_lifetime', 'Your church has lifetime access. A new purchase is not required.');
        }

        return BillingAvailabilityDecision::allow();
    }

    public function present(?Tenant $tenant): array
    {
        $quote = $this->decide($tenant, BillingAction::QuotePurchase);
        $online = $this->decide($tenant, BillingAction::CreateOnlineOrder);
        $offline = $this->decide($tenant, BillingAction::SubmitOfflineClaim);

        return [
            'can_quote' => $quote->allowed,
            'can_checkout_online' => $online->allowed,
            'can_submit_offline_claim' => $offline->allowed,
            'reasons' => [
                'quote' => $quote->allowed ? null : ['code' => $quote->reasonCode, 'message' => $quote->message],
                'online' => $online->allowed ? null : ['code' => $online->reasonCode, 'message' => $online->message],
                'offline' => $offline->allowed ? null : ['code' => $offline->reasonCode, 'message' => $offline->message],
            ],
        ];
    }
}
