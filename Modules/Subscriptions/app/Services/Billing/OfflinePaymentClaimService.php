<?php

namespace Modules\Subscriptions\Services\Billing;

use Modules\Authentication\Models\User;
use Modules\Subscriptions\Billing\BillingAction;
use Modules\Subscriptions\Billing\BillingAvailabilityPolicy;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\BillingInvoice;
use Modules\Subscriptions\Models\BillingOfflineClaim;
use Modules\Subscriptions\Models\BillingOfflineMethod;
use Modules\Tenants\Models\Tenant;

final class OfflinePaymentClaimService
{
    public function __construct(
        private readonly BillingAvailabilityPolicy $availability,
        private readonly BillingAuditService $audit,
        private readonly BillingNotificationPublisher $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function submit(Tenant $tenant, User $user, array $input): BillingOfflineClaim
    {
        $decision = $this->availability->decide($tenant, BillingAction::SubmitOfflineClaim);
        if (! $decision->allowed) {
            throw SubscriptionException::billingDenied($decision->reasonCode, $decision->message);
        }

        $methodCode = (string) ($input['method_code'] ?? '');
        $method = BillingOfflineMethod::query()->where('code', $methodCode)->where('enabled', true)->first();
        if ($method === null) {
            throw SubscriptionException::changeNotAllowed('This payment method is not available.');
        }

        $invoiceId = isset($input['billing_invoice_id']) ? (int) $input['billing_invoice_id'] : null;
        if ($invoiceId !== null) {
            $invoice = BillingInvoice::query()->where('tenant_id', $tenant->id)->whereKey($invoiceId)->first();
            if ($invoice === null) {
                throw SubscriptionException::billingDenied('forbidden', 'Invoice not found.');
            }
        }

        $claim = BillingOfflineClaim::query()->create([
            'tenant_id' => $tenant->id,
            'submitted_by_user_id' => $user->id,
            'billing_invoice_id' => $invoiceId,
            'billing_order_id' => isset($input['billing_order_id']) ? (int) $input['billing_order_id'] : null,
            'method_code' => $methodCode,
            'claimed_amount' => (string) $input['claimed_amount'],
            'currency_code' => strtoupper((string) ($input['currency_code'] ?? 'INR')),
            'paid_on' => $input['paid_on'] ?? null,
            'reference_number' => $input['reference_number'] ?? null,
            'remitter_name' => $input['remitter_name'] ?? null,
            'notes' => $input['notes'] ?? null,
            'status' => BillingOfflineClaim::STATUS_PENDING,
        ]);

        $this->audit->record('offline_claim_submitted', $user->id, null, (int) $tenant->id, null, null, [
            'claim_id' => $claim->id,
        ]);

        $this->notifications->offlineClaimSubmitted($claim);

        return $claim;
    }
}
